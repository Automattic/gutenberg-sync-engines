---
id: 0008
title: Decide whether the Automerge library we ship should do any work
status: shaping
size: large
area: de-rtc
github:
---

# Decide whether the Automerge library we ship should do any work

## What happens now

We ship a PHP copy of a library called Automerge, which is a well known
way of merging documents that people edited at the same time. It is
about as much code as the rest of the de-rtc engine put together.

It never runs. Every real edit takes a different route through the code,
one we wrote ourselves, which merges block by block. The library sits
behind a check that no real edit ever satisfies. The only thing that
exercises it is its own test suite.

Nothing is broken. The merging we do works, and it is the merging the
original design intended. But we carry the library, we keep it working,
and our public write-up leaves the impression that it does something.

So this is a decision, not a bug: should it earn its place, or should we
say plainly that it is a reference copy?

## Example

1. Search for the one place the library gets used:

```bash
grep -rn "get_automerge_native_port" includes/
```

2. Look at the check just above the single result in
   `merge-core.php`. It runs only when an edit arrives in an older
   format.
3. Search for where our editor stamps the format on an edit, in
   `src/engines/de-rtc/descriptor.ts`. It always stamps the newer one.

**What you see:** one route into the library, and nothing that takes it.

**What you expected:** a library we ship and maintain to be doing
something.

## What should happen instead

One of two things, decided deliberately:

- The library does real work: it becomes the last thing we try before
  giving up on a merge and asking a person to decide.
- Or it is reference material, we say so in the documentation, and we
  stop implying otherwise.

## How we will know it is done

Do the smallest experiment that can kill the idea, before building
anything. It is a test file, no browser and no WordPress:

Build a document with the library, have two pretend people edit the same
paragraph from the same starting point, merge, turn the result back into
blocks, and check that the blocks survive with their settings intact.
Then measure how long that takes for posts of ten, fifty, and two
hundred blocks.

**Stop and choose reference material if either is true:** it is slower
per edit than the yjs-server engine, or blocks come back damaged.

If it survives, the work after that is a schema, a place to store the
document, and wiring it in as a last resort before we ask a person.
Roughly a week, and that estimate assumes the experiment goes well.

## Notes for whoever picks this up

Written up first on 19 August and left undecided for a while. The
original, longer write-up is in the git history of the deleted `V1.md`
(`git log --diff-filter=D -- V1.md`); it carries the detail this
summary leaves out, including exact line numbers, a phased plan, and
why the unused route stays unreachable even if something called it.

**Read the reasons against it before starting.** They are good reasons,
and someone wrote them down when they had the whole picture:

- It fixes no known bug.
- It works against what makes this engine different. This engine's
  selling point is that it stops and asks a person when two people
  genuinely clash. A library like this never stops and asks; it always
  produces an answer. So it can only help in the narrow band of cases
  we currently hand to a person, which is the band we are proud of.
- It would make PHP 8.2 a hard requirement, and full correctness needs
  PHP 8.4. Today that is optional and nobody notices.
- It puts heavy merging work into the engine meant for cheap hosting.
- It moves us away from the upstream design we currently copy exactly,
  and the difference becomes ours to maintain forever.

The counter-argument, in one sentence: our own merging code is
hand-written and new, and a well-tested library doing the hardest part
of the job has value that is hard to see until the hand-written version
gets something subtly wrong.

There is a fourth option in the original write-up, which is to use this
library properly on both the browser and the server. That is a rewrite
of how de-rtc works and effectively makes it a second copy of the
yjs-server engine. It was not recommended and it should not be revived
without a much stronger reason.
