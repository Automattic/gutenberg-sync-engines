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

These are all real words we use in the code, and they are correct
there. They just do not belong in the part of an item that a newcomer
reads first. Use the plain version instead, and save the precise term
for the notes at the bottom.

| We say | Write this instead |
| --- | --- |
| room | the post everyone is editing |
| intent | a single change someone made |
| seq / base-seq | which version of the post they started from |
| stale base | they were working from an old version |
| voided | the change was thrown away |
| escalated / parked | set aside for a person to decide |
| disposition | what the server decided to do with a change |
| canonical content | the official copy of the post on the server |
| materialize | turn the saved data back into post content |
| genesis | the first copy of the post the server stores |
| CRDT | a document that merges itself |
| transform | adjust a change so it still fits |
| coarse capture | a change we recorded roughly, not precisely |

Add to this table whenever you catch yourself explaining a word.

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

## Mirroring to GitHub

The mirror is one-way. Files here are the source of truth. A script
pushes each file to a GitHub Issue and writes the issue number back
into the file. Two-way sync always rots, so we do not do it.

Comments and discussion live on the issue. The spec lives here.
