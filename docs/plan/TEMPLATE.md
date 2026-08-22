---
id: 0000
title: Say what a person sees, not what the code does
status: shaping
size: small
area: intent-log
github:
---

# Say what a person sees, not what the code does

## What happens now

Two or three sentences. What does a person doing ordinary work run
into? No jargon. If this is a new feature rather than a bug, use this
section to describe what people cannot do today.

## Example

Numbered steps someone could follow to see it themselves.

1. Open a post in two browser windows, signed in as two different people.
2. In window A, do the specific thing.
3. In window B, do the other specific thing.

**What you see:** the actual wrong result, quoted exactly.

**What you expected:** the result that would be correct.

## What should happen instead

One short paragraph. The correct behavior, described the way you would
describe it to the person who reported the problem.

## How we will know it is done

The command to run, and what a passing result looks like:

```bash
npm run test:js -- some-pattern
```

If there is no command yet, say what someone should check by hand, and
add "needs a test" to the notes below.

## Notes for whoever picks this up

This is where the deep detail goes, and it should be as precise as you
like. File paths, the exact mechanism, what has already been tried,
which fixes are already merged, and any dead ends.

Link related items like this: [0002](0002-some-other-item.md).
