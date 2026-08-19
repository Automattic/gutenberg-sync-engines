---
description: Run one bounded cycle of the v1 loop (executor half; verifier is the v1-verifier agent)
---

You are the EXECUTOR for exactly one cycle of this repo's v1 loop.
`V1.md` is the frozen scope contract; `LOOP.md` is the ledger. Do ONE
bounded unit of work, leave the ledger accurate, and stop.

## Cycle protocol

1. **Load state.** Read `V1.md` and `LOOP.md`. If every Lane-A item is
   `done` or `parked` and every Lane-B item is `awaiting-human` or
   `parked`, set the loop status to COMPLETE, write a final summary in
   the cycle log, commit the ledger, report, and stop the loop
   (if running under /loop dynamic pacing, end it rather than schedule
   another wakeup).
2. **Preflight.** Run `npm run doctor`. If it reports real problems:
   fix quickly if the fix is obvious and local; otherwise record the
   diagnosis in the ledger and end the cycle as a no-op. Never work
   around a broken environment — uniform cross-engine timeouts are an
   environment failure, not an engine bug. In a worktree, keep the
   DIRECTORY-NAME plugin copy active in wp-env (AGENTS.md gotcha).
3. **Claim work.** Continue the `in-progress` item if one exists;
   otherwise claim the topmost `queued` item whose dependencies are
   met. Mark it `in-progress`, bump its attempt count, record the
   branch name.
4. **Work on a branch.** Each item lives on `loop/<item-id>` (e.g.
   `loop/a6`), created from the base branch on first claim. One bounded
   unit per cycle: for batchable items (A6 phpcs) that means one
   directory or sniff family; for suite-heavy items one suite run plus
   the fixes it motivates. Side discoveries become new ledger entries —
   never drive-by fixes. Return to the base branch before ending the
   cycle.
5. **Verify cheaply first.** Use the test ladder in AGENTS.md (sweep →
   Jest → conformance → PHPUnit → e2e → fuzz) at the cheapest layer
   that can catch the change, then run the item's acceptance commands
   from V1.md. Serialize suites: never run `test:php` while e2e is in
   flight; one tests-env consumer at a time.
6. **Commit.** Commit completed work on the item branch with
   `--no-verify`. Never merge, never push, no PRs, no outward-facing
   actions of any kind — merging is the human's move at sign-off.
7. **Request verification.** When the item's acceptance commands pass,
   spawn the `v1-verifier` agent with ONLY: the item ID, the item
   branch name, and the base ref. Do not send your reasoning, your
   diagnosis, or a summary of the work — the verifier judges artifacts,
   not narrative. Verdict PASS → mark the item `done` (record the
   verdict in the cycle log). Verdict FAIL → record the verifier's
   reasons verbatim in the ledger; the item stays `in-progress` and the
   next cycle addresses the reasons.
8. **Escalation triggers** (from V1.md — checked continuously, not at
   the end): the fix needs a `gutenberg/` subtree edit, a frozen
   surface, a wire/storage/protocol shape change, or a weakened test —
   reclassify the item to Lane B, park it with a diagnosis, and move
   on. Three failed attempts → `parked` with a diagnosis. Parking is a
   normal outcome, not a failure; thrashing is the failure.
9. **Lane B items** stop at a proposal: implement on the item branch,
   write `proposals/<item-id>.md` (what changed and why, the diff's
   shape, verification evidence, open questions for the human), commit
   both, mark the item `awaiting-human`. For B1: keep the subtree diff
   minimal and revert any tracked build side-effects in `gutenberg/`.
10. **Update the ledger.** Every cycle ends by updating `LOOP.md`
    (statuses, a cycle-log entry in its documented format, any new
    lessons) and committing that update on the BASE branch with
    `--no-verify`. A cycle that changed nothing still logs why.
11. **Report and pace.** End with a short report: what happened, the
    verifier's verdict if any, what the next cycle will do. Under /loop
    dynamic pacing: schedule the next wakeup — short (~60–300 s) when
    mid-item with more bounded units ready, longer (~1200 s+) when the
    next step waits on a long-running suite or environment, and stop
    entirely when the queue is exhausted.

## Standing rules

- The maker is never the grader: only a `v1-verifier` PASS marks an
  item `done` — your own green run is necessary, never sufficient.
- V1.md is read-only for you. Scope questions, new items, and
  reclassifications go through the ledger as `parked`/`awaiting-human`
  entries for the human; do not edit the contract.
- Report failures faithfully in the ledger: failing output verbatim,
  skipped steps named. The ledger is only useful if it is honest.
- Budget/interruption safety: prefer states that park cleanly — commit
  work-in-progress on the item branch with a `wip:` prefix and note the
  resume point in the ledger before ending a cycle early.
