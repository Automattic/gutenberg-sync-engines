# The plan folder

This is where work gets described before anyone builds it.

One file per item, in `items/`. Each file describes one thing that a
person using the editor would notice. The file is the source of truth.
GitHub Issues are a mirror of these files, so discussion has a home,
but the file is what we edit.

Everything else in `docs/` explains how the system works today. This
folder is only about what we plan to change.

## The rules

**One item is one thing.** If the title needs the word "and", it is
probably two items. Split it.

**Plain language at the top.** Someone who has never opened this repo
should understand the title, the problem, and the example. Write for a
new engineer joining next week, not for the person who found the bug.

**Every item has a real example.** Numbered steps. Real text someone
would type. What they saw. What they expected.

**Every item says how we will know it is done.** A command if there is
one. A description of the correct behavior if there is not.

**The deep technical detail goes at the bottom**, under "Notes for
whoever picks this up". It is welcome there. It is not welcome in the
first three sections.

## The read-aloud test

Read the title and the first paragraph out loud. If someone outside
this project would have to stop and ask what a word means, change the
word.

## Words to avoid at the top of an item

Here is the test, and it is mechanical: **if a word appears in
[the glossary](../glossary.md), it is one of our invented words.** Do
not use it in the title, the problem, or the example. Say what it means
instead.

Room, genesis, materialize, disposition, void, park, escalate,
register, salvage, sequester, incorporate, announce, checkpoint — all
of these are correct in the code and correct in the rest of `docs/`.
They are wrong at the top of a plan item, because the person reading
that part may be meeting this project today.

They are welcome in the notes at the bottom. Link the glossary when you
use one there.

Some plain replacements, to show what this looks like in practice:

| Instead of | Write |
| --- | --- |
| the room | the post everyone is editing |
| the intent was voided | the change was thrown away |
| it escalated / parked | it was set aside for a person to decide |
| a stale base | they were working from an old version of the post |
| canonical content | the official copy on the server |

## Status values

- `shaping` — the problem is real but we have not decided what to build
- `ready` — anyone could pick this up and start
- `in progress` — someone is on it
- `done` — shipped and verified

An item that is `shaping` should say what decision is missing.

## Sizes

- `small` — a day or less
- `medium` — a few days
- `large` — needs breaking up before anyone starts

## Checking an item

```bash
npm run plan:check
```

It reports our invented words used above the notes section, missing
sections, and examples without numbered steps. The jargon check reads
`../glossary.md`, so it is eager on purpose — look at each word it
flags and decide.

## Mirroring to GitHub

The mirror is one-way. Files here are the source of truth. Issue
numbers land in each file's frontmatter once mirrored.

```bash
node docs/plan/mirror.mjs --dry-run   # preview
node docs/plan/mirror.mjs             # push
```

**Run it yourself.** It creates and edits issues on the public
repository, so it is not something to hand to an agent in passing.

Comments and discussion live on the issue. The spec lives here. If an
issue and its file disagree, the file wins.

## When an item ships

Update `CHANGELOG.md` as part of the change, per `AGENTS.md`. Set the
item's status to `done`. Leave the file in place; it is the record of
why the change happened.
