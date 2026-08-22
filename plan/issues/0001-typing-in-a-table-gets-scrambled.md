---
id: 0001
title: Typing fast in a table cell can scramble your own text
status: ready
size: medium
area: intent-log
github:
---

# Typing fast in a table cell can scramble your own text

## What happens now

Two people are editing the same post. One of them types quickly inside
a table cell or a quote block. Every so often, the words they just
typed come out jumbled on their own screen. Letters land in the wrong
places, as if the text had been shuffled.

The strange part is that the other person's screen looks fine, and so
does the saved post. Only the person who did the typing sees the mess.
That makes it feel like the editor is broken, because they watched
their own words come apart as they typed them.

This happens roughly one or two times out of eight when the site is
busy. On a fast machine you may not see it at all.

## Example

1. Open the same post in two browser windows, signed in as two
   different people.
2. In window A, type steadily in a paragraph so the post keeps changing.
3. In window B, type `ed by B` into a table cell that already contains
   the word `Quoted text`.

**What you see** in window B: `Quoted texted by Bt`

**What you expected:** `Quoted texted by B`

The letters are the right letters. They arrive in the wrong order and
one gets stranded at the end.

## What should happen instead

Text should appear exactly as it was typed, every time, no matter how
busy the post is or how fast someone types.

## How we will know it is done

This command runs the same editing test eight times in a row. It
currently fails one or two of those eight runs. It should pass all
eight:

```bash
npm run test:e2e -- --repeat-each=8 -g "mix of block types"
```

Because the failure only shows up sometimes, run the command twice
before believing a fix worked.

## Notes for whoever picks this up

**Why it happens.** The server keeps a list of every change people
make to a post. When that list gets long, the server saves a summary
and throws away the old entries. Each keystroke in a table cell adds
three entries to that list instead of one, because we record table
edits roughly rather than precisely. So a person typing in a table
fills the list about three times faster than anyone else.

If the list gets trimmed in the middle of someone's typing, the
keystrokes still in flight are pointing at entries that no longer
exist. The server throws those keystrokes away, and the ones that
follow are measured from the wrong starting point, so they get
inserted at the wrong positions.

**What is already fixed.** This was loop item A12. Three partial fixes
merged in PR #35 and are covered by tests. They closed two related
failures: the other person losing the text silently, and the editor
overwriting live work when it caught up. What remains is only the
typist's own screen.

**The two ways to fix the rest.** Both attack the cause rather than the
timing:

1. Batch the table-cell entries so one keystroke writes one entry
   instead of three. This is the recommended direction. It removes the
   reason a typing burst fills the list fast enough to trigger a trim.
2. Slow down or delay the trim so it never lands in the middle of
   someone's typing burst.

Raising the trim threshold is not a fix. It was already raised from
100 to 500 entries in loop item A15, which made the problem rarer but
did not remove it.

**Where the code lives.** `src/engines/intent-log-manager.ts` records
the edits. The trim happens on the server in
`includes/engines/intent-log/`. The related tests are in
`tests/js/engines/intent-log-manager.test.ts`.
