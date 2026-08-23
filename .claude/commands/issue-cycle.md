---
description: Run one bounded cycle of work on a GitHub issue labelled agent:ready (executor half; the grader is the issue-verifier agent)
---

You are the EXECUTOR for exactly one cycle of this repo's issue loop.

**GitHub Issues are the source of truth** for what needs doing.
`LOOP.md` is the ledger: what happened, and what was learned. Do ONE
bounded piece of work, leave the ledger accurate, and stop.

## The cycle

1. **Load state.** Read `LOOP.md`, then get the queue:

   ```bash
   gh issue list --label "agent:in progress" --state open
   gh issue list --label "agent:ready" --state open
   ```

   If both are empty, say so, write a closing note in the cycle log,
   commit the ledger, and stop the loop rather than scheduling another
   wakeup. Read the issue you are going to work on in full, comments
   included: `gh issue view <n> --comments`.

2. **Preflight.** Run `npm run doctor`. If it reports real problems: fix
   them if the fix is obvious and local, otherwise write the diagnosis
   in the ledger and end the cycle having done nothing else. Never work
   around a broken environment. Timeouts that happen the same way
   across every engine are an environment failure, not a bug in the
   code.

3. **Claim it.** Continue an `agent:in progress` issue if there is one.
   Otherwise take the oldest `agent:ready` issue:

   ```bash
   gh issue edit <n> --add-label "agent:in progress" --remove-label "agent:ready"
   ```

   Record it in `LOOP.md` as the current issue.

4. **Work on a branch** named `loop/<issue number>`, created from the
   base branch the first time. One bounded piece per cycle. Anything you
   discover along the way is reported at the end, never fixed in
   passing. Return to the base branch before the cycle ends.

5. **Check cheaply first.** Use the ladder in `AGENTS.md` — the
   simulator sweep, then unit tests, then PHP tests, then browser
   tests, then the fuzzer — at the cheapest level that could catch what
   you changed. Then run the issue's own "How we will know it is done"
   commands, exactly as written. Never run the PHP tests while browser
   tests are in flight; they wipe the same database.

6. **Commit** on the issue branch with `--no-verify`. Never merge,
   never push, never open a pull request. Merging is the human's move.

7. **Ask for verification.** When the issue's stated checks pass, spawn
   the `issue-verifier` agent with ONLY the issue number, the branch
   name, and the base ref. Do not send your reasoning or a summary — it
   judges the work, not the story about the work. PASS means the work
   is done: leave the issue open with `agent:in progress` for the human
   to close on merge, and record the verdict. FAIL means you record its
   reasons word for word in the ledger, and the next cycle deals with
   them.

8. **Stop and write it down when you are stuck.** Three failed attempts
   means parking it:

   ```bash
   gh issue comment <n> --body-file diagnosis.md
   gh issue edit <n> --add-label "agent:parked" --remove-label "agent:in progress"
   ```

   The diagnosis says what you tried, what you learned, and what the
   next person should do differently. Every park says what the issue
   needs to move again — that is what makes it a park rather than an
   abandonment. Parking is a normal outcome. Trying the same thing a
   fourth time is not.

9. **Escalate instead of pressing on** when the work turns out to need
   any of these. Comment on the issue saying exactly what it now needs
   and from whom, move it to `agent:parked`, and go on to the next
   issue:
   - a change inside the `gutenberg/` copy of the framework
   - a change to frozen code: the intent-log core and its PHP twin, the
     de-rtc merge core, or the bundled y-php and automerge-php
   - a change to the shape of anything sent over the wire or stored
   - weakening or deleting a test to get a pass
   - a product decision that is genuinely the human's to make

10. **Update the ledger.** Every cycle ends by updating `LOOP.md` — the
    current issue, a cycle-log entry in the documented format, any
    lesson worth keeping — and committing that on the BASE branch with
    `--no-verify`. A cycle that changed nothing still records why.

11. **Report and pace.** Finish with a short plain report: what
    happened, the verdict if there was one, what the next cycle will
    do. Under `/loop` pacing, schedule the next wakeup — soon when
    there is more bounded work ready, much later when the next step
    waits on a long suite, and stop entirely when the queue runs out.

## Standing rules

- **The person who does the work does not declare it done.** Your own
  green test run is necessary and never sufficient.
- **Labels and comments are yours. Scope is not.** You may move an
  issue between `agent:` labels and comment on it freely. Do not
  rewrite an issue to mean something else, do not close one, and do not
  file new ones — report those to the user instead. Shaping is
  `/shape-issues`, a separate job.
- **An issue that is not `agent:ready` is not ready.** If you find
  yourself deciding what the issue should have said, stop: that is
  shaping, and it needs to happen before the work, not during it.
- **Report failures honestly.** Failing output word for word, skipped
  steps named. The ledger is worth nothing if it is optimistic.
- **Park cleanly if you run out of budget.** Commit unfinished work on
  the issue branch with a `wip:` prefix and write the resume point in
  the ledger before the cycle ends.
- **Write plainly**, in the ledger, in issue comments, and in commit
  messages. The rules in `docs/plan/README.md` apply to everything the loop
  writes.
- **Do not hard-wrap anything going to GitHub.** Issue comments and
  pull request bodies render every newline inside a paragraph as a
  visible break. One paragraph, one line. `LOOP.md` and commit messages
  are files and stay wrapped as usual.
