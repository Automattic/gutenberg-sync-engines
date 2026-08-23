# LOOP.md — the working ledger

This is the loop's working state: what it is on, what happened, and
what it learned.

**The queue is not here.** It is GitHub Issues:

```bash
gh issue list --label "agent:ready"        # waiting to be worked
gh issue list --label "agent:in progress"  # being worked now
gh issue list --label "agent:needs shaping" # filed, not investigated yet
```

**Loop status:** READY — v1 is closed and merged.
**Base branch:** trunk
**Current issue:** none

## How to run it

```
/shape-issues        # turn filed reports into issues anyone could pick up
/loop /issue-cycle   # work through everything labelled agent:ready
```

One issue per branch, named `loop/<issue number>`. One bounded piece of
work per cycle. The full protocol is in
`.claude/commands/issue-cycle.md`; the writing rules are in
`docs/plan/README.md`; the standing repo rules are in `AGENTS.md`.

## Parked

Three, all waiting on a decision rather than on work. Each carries a
comment saying what it needs:

- **#40** — when is it safe to stop accepting the old way of sending
  Adopt and Reject, and what happens to the types the new route does
  not cover?
- **#42** — what does a websocket notification say, and is it a fourth
  transport or a mode of the existing one?
- **#44** — does anyone want the Automerge question answered? It is a
  week, and nothing is broken either way.

A park is recorded as a comment on the issue plus the `agent:parked`
label — that is where the next person will look. Note it here too, in
one line, so the ledger reads straight through.

## Cycle log

Newest first. One entry per cycle, including cycles that changed
nothing.

```
### Cycle N — YYYY-MM-DD — #<issue> <short name>
- Did: <one bounded piece of work, in a sentence or two>
- Checked: <the commands run and what they said>
- Verifier: PASS / FAIL — <reasons if FAIL> (or "not requested")
- Ledger: <label changes, anything reported to the user>
```

No cycles yet. The v1 run that came before this is summarised in
`docs/plan/history.md`; its cycle-by-cycle record is in this file's git
history and is not worth reading unless you are studying the loop
itself.

## Lessons

Things this loop learned the hard way. Keep them short, and move
anything that stops being about *process* into `docs/plan/history.md` or
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
