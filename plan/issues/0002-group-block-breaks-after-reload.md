---
id: 0002
title: A Group block can break after a reload, showing the recovery message
status: ready
size: medium
area: yjs-server
github:
---

# A Group block can break after a reload, showing the recovery message

## What happens now

Someone is editing a post that has a Group block with other blocks
inside it. They reload the page. Sometimes the Group comes back broken:
WordPress shows its "This block contains unexpected or invalid content"
message with the **Attempt Block Recovery** button, in place of the real
block.

Recovery does not help much, because the copy WordPress kept to recover
from is empty. The person is left deciding whether to delete the block
and rebuild it.

This only happens with the yjs-server engine, and only sometimes. It
depends on the exact order edits happened in, not on which browser or
connection is used.

## Example

We do not yet have hand steps that trigger this reliably. The automated
editing test does trigger it, and this command replays the exact run:

1. Start the tests environment.
2. Run the replay:

```bash
npm run fuzz -- --combos=yjs-server/http-polling --seed-list=3 --steps=12
```

3. Look at the final screenshot the run saves.

**What you see:** the Group block replaced by WordPress's invalid
content message.

**What you expected:** the Group block, with its contents, exactly as it
was before the reload.

Note that the run passes when repeated immediately afterwards, so treat
one clean run as meaningless. Run it several times.

## What should happen instead

A Group block, and anything else that holds other blocks inside it,
should come back after a reload exactly as it was saved.

## How we will know it is done

The replay command above passes five times in a row. Once there are hand
steps that reproduce it, add a regular test for those too, because a
test we can run in a second beats one that takes minutes.

## Notes for whoever picks this up

Found by the full automated sweep at the end of the v1 work, filed there
as F1. It reproduces the same way on the version of the code from before
that work, so this is not something we recently broke. It had simply
never been tested with these particular editing sequences.

Two suspects, both in how yjs-server builds and rebuilds blocks that
contain other blocks:

1. Blocks the server creates must be marked `isValid: true`. When they
   are not, the editor shows exactly this recovery message. This has
   caused the same symptom before.
2. Rebuilding a container's saved HTML has to place the child blocks
   back inside the parent's wrapper. That code changed recently: PR #35
   taught each stored block to carry its own saved HTML, and the
   proposal that shipped it flagged unusual container types as the part
   that deserved a second pair of eyes. This may be that.

The empty recovery copy is the strongest clue: it suggests the block
arrived with no saved HTML at all, rather than with HTML the editor
disagreed with.

Start in `includes/engines/yjs-server/class-wp-yjs-server-engine.php`,
in the code that turns stored blocks back into post content.
