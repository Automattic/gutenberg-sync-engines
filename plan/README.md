# How we plan work

**Work lives in GitHub Issues.** Anyone can file one, read one, or
argue with one without a checkout. This folder holds the things that
are not issues: why the code is shaped the way it is
([history.md](history.md)), what we looked at and set aside
([wontfix.md](wontfix.md)), and the rules below.

> **Migration pending.** `issues/` still holds the eight issues written
> before this move. They have not been filed on GitHub yet. Once they
> are, that folder goes away.

## The two ways in

**A human files a report.** Two required fields: what happened, and
what you expected. That is a complete report. You do not need to know
how anything works, and you do not need to use our words for things.
It arrives labelled `agent:needs shaping`.

**An agent shapes it.** Someone investigates, works out what is
actually going on, and rewrites the issue body into the full shape
below. The label moves to `agent:ready`. The reporter's original words
stay in the issue's edit history.

That is the whole point of the split: a report costs a person two
minutes, and the expensive part is done by whoever picks it up.

## The shape of a shaped issue

Defined by
[`.github/ISSUE_TEMPLATE/shaped-issue.md`](../.github/ISSUE_TEMPLATE/shaped-issue.md),
which is the one copy — do not restate it here.

Five sections: what happens now, an example with numbered steps, what
should happen instead, how we will know it is done, and notes for
whoever picks it up.

## The rules

**One issue is one thing.** If the title needs "and", it is two issues.

**Plain language at the top.** The title, the problem, and the example
are read by people meeting this project today. Write for them.

**The deep technical detail goes in the notes section at the bottom.**
It is welcome there, in full. It is not welcome above.

**Every issue has a real example** with numbered steps, real text, what
you saw, and what you expected.

**Every issue says how we will know it is done** — a command if there
is one.

### The jargon rule, and who it binds

If a word is defined in [`docs/glossary.md`](../docs/glossary.md), it is
one of ours. Do not use it in the title, the problem, or the example.
Say what it means instead, and save the precise term for the notes.

Room, genesis, materialize, disposition, void, park, escalate,
register, salvage, sequester, incorporate, announce, checkpoint — all
correct in the code, all wrong at the top of an issue.

| Instead of | Write |
| --- | --- |
| the room | the post everyone is editing |
| the intent was voided | the change was thrown away |
| it escalated / parked | it was set aside for a person to decide |
| a stale base | they were working from an old version of the post |
| canonical content | the official copy on the server |

**This rule binds agents, not reporters.** A person describing their
own problem is plain by default. The risk is entirely ours.

## Checking before you file

```bash
node plan/bin/check.mjs draft.md            # a body you are about to file
node plan/bin/check.mjs --issue 12          # one already on GitHub
node plan/bin/check.mjs --label agent:ready # everything with a label
```

It reports jargon above the notes section, missing sections, and
examples without numbered steps. The jargon check is eager on purpose:
look at each word and decide.

## Labels

Anything an agent maintains carries an `agent:` prefix. Everything else
(`bug`, `enhancement`, `documentation`, engine names) is free for
anyone to use.

| Label | Means |
| --- | --- |
| `agent:needs shaping` | Filed, not yet investigated. The front door. |
| `agent:ready` | Shaped. Someone could pick it up today. |
| `agent:in progress` | A loop cycle is working on it. |
| `agent:needs decision` | Shaped, but a human has to decide something before work starts. The issue says what. |
| `agent:parked` | Three failed attempts. A comment records what was tried and what to do differently. |

Create them once:

```bash
gh label create "agent:needs shaping" -c "#FBCA04" -d "Filed, not yet investigated by an agent"
gh label create "agent:ready"         -c "#0E8A16" -d "Shaped and ready to work on"
gh label create "agent:in progress"   -c "#1D76DB" -d "A loop cycle is working on this"
gh label create "agent:needs decision" -c "#D93F0B" -d "Shaped, but blocked on a human decision"
gh label create "agent:parked"        -c "#5319E7" -d "Three failed attempts; see the diagnosis comment"
```

## Working through them

```
/shape-issues          # investigate what was filed, write it up properly
/loop /issue-cycle     # work through everything labelled agent:ready
```

`LOOP.md` is the ledger while the loop runs. The queue itself is
GitHub:

```bash
gh issue list --label "agent:ready"
```

## When an issue ships

Update `CHANGELOG.md` as part of the change, per `AGENTS.md`. Close the
issue. If it taught us something that would save the next person a
week, add a line to [history.md](history.md).
