# LOOP.md — the working ledger

This is the loop's working state: what is being worked on, what
happened, and what was learned. The issues themselves live in
`plan/issues/` and are the source of truth for what each one is. This
file only tracks progress against them.

The loop reads this every cycle and writes to it every cycle. Humans
can edit it freely.

**Loop status:** READY — v1 is closed and merged; the queue below is
the next run.
**Base branch:** trunk
**Current issue:** none

## How to run it

```
/loop /issue-cycle
```

One issue per branch, named `loop/<number>` (for example `loop/0002`).
One bounded piece of work per cycle. The full protocol is in
`.claude/commands/issue-cycle.md`; the standing rules are in
`AGENTS.md`.

## Queue

Ordered on purpose: things that can be proved come first, so the loop
builds a record of verified work before it reaches anything ambiguous.
A cycle takes the topmost issue whose status is `ready`.

| # | Issue | Status | Size | Attempts | Branch | Notes |
| --- | --- | --- | --- | --- | --- | --- |
| 0002 | [Group block breaks after a reload](plan/issues/0002-group-block-breaks-after-reload.md) | ready | medium | 0 | — | Has a replay command that fails today. Best first issue: provable both ways |
| 0001 | [Typing in a table gets scrambled](plan/issues/0001-typing-in-a-table-gets-scrambled.md) | ready | medium | 0 | — | Cause is known and the fix direction is written down. Fails 1-2 runs in 8, so judge it over many runs |
| 0003 | [Two people stop agreeing when requests fail](plan/issues/0003-two-people-stop-agreeing-when-requests-fail.md) | ready | medium | 0 | — | First cycle's whole job is making it fail reliably. Do not attempt a fix before that |
| 0005 | [Approving risky content may not stick](plan/issues/0005-approving-risky-content-may-not-stick.md) | shaping | medium | 0 | — | Starts with a test that proves whether the problem is real. That much is loop work; the fix needs a human decision after |
| 0007 | [Presence on slow connections](plan/issues/0007-presence-on-slow-connections.md) | shaping | medium | 0 | — | Starts with a measurement. Same shape as 0005: measure first, decide after |
| 0004 | [Remove the old way of sending Adopt and Reject](plan/issues/0004-remove-the-old-way-of-sending-adopt-and-reject.md) | shaping | small | 0 | — | Blocked on a human decision about when it is safe. Do not start it before that |
| 0006 | [A cheaper way to use websockets](plan/issues/0006-a-cheaper-way-to-use-websockets.md) | shaping | large | 0 | — | Needs shaping and a human decision on the message format. Too big for the loop as written |
| 0008 | [Should the Automerge library do any work](plan/issues/0008-decide-whether-the-automerge-library-should-do-work.md) | shaping | large | 0 | — | The first step is a bounded experiment with stated stopping conditions. Worth a cycle only if a human wants the question answered now |

Statuses match the issue files, and the issue file is authoritative. If
they disagree, fix the issue file and copy it here.

Add: `in progress` while a cycle is on it, `blocked` when something
outside the loop is in the way, and `parked` after three failed
attempts, which always needs a written diagnosis below.

## Parked

Nothing parked.

When something lands here, record what was tried, what was learned, and
what the next person should do differently. A park is a normal outcome.
Repeating a failed approach is not.

## Cycle log

Newest first. One entry per cycle, including cycles that changed
nothing.

```
### Cycle N — YYYY-MM-DD — <issue number and short name>
- Did: <one bounded piece of work, in a sentence or two>
- Checked: <the commands run and what they said>
- Verifier: PASS / FAIL — <reasons if FAIL> (or "not requested")
- Ledger: <status changes, anything newly filed>
```

No cycles yet on this queue. The v1 run that came before it is
summarised in `plan/history.md`; its full cycle-by-cycle record is in
this file's git history, and is not worth reading unless you are
studying the loop itself.

## Lessons

Things this loop learned the hard way. Keep them short, and move
anything that stops being about *process* into `plan/history.md` or
`AGENTS.md`.

- Run an issue's stated check exactly as written before claiming it
  passes. A test filter that matches nothing exits successfully and
  looks green.
- Check `git log <base>..<branch>` before asking for verification.
  Another session can commit onto the branch you are on, and the
  verifier will fail the whole issue for someone else's change.
- Rebuild the plugin bundle before any browser-based check. Unit tests
  do not need it; anything involving a real browser does, and a stale
  bundle produces evidence for code you did not write.
- Do not switch branches while a background build for that branch is
  running. The build reads whatever is on disk when it gets there.
