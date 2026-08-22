---
id: 0005
title: Approving risky content may not stick the next time someone edits it
status: shaping
size: medium
area: de-rtc
github:
---

# Approving risky content may not stick the next time someone edits it

## What happens now

**We are not certain this happens. Step one is proving it.** What
follows is what reading the code suggests, and it matters enough to
check.

WordPress only lets some people publish raw HTML. When an author who
lacks that permission includes something like a custom embed, de-rtc
does not throw it away and does not let it through. It puts that one
block back the way it was and holds the author's version for review.
Someone with the right permission then looks at it and approves it. That
part works.

The question is what happens afterwards. If a person without permission
later edits that same block, even to fix a typo, the approval may not
carry over, and the block may get held for review all over again. If so,
an approved paragraph containing an embed becomes a paragraph nobody
without permission can ever touch again without tripping review.

Our public write-up says approvals are stamped and survive future
edits. The code we ported deliberately left out the machinery that would
do the stamping. So either the write-up is wrong or the behavior is, and
we should find out which.

## Example

1. As an administrator, write a post with a paragraph, and start a
   collaborative session on it.
2. As an author without the unfiltered HTML permission, edit that
   paragraph to include something WordPress would strip.
3. As the administrator, approve the held-back block.
4. As the same author, go back and fix a typo elsewhere in that block.

**What we expect to see, and need to confirm:** the block is held for
review again, even though its risky content was already approved.

**What should happen:** the typo fix lands, because the risky part was
already approved and has not changed.

## What should happen instead

Once someone with the right permission approves specific content, that
content should stay approved while it is unchanged, no matter who edits
the rest of the block.

## How we will know it is done

The first piece of work is a test that reproduces the four steps above.
Write it whichever way it actually behaves, so we have the truth
recorded:

```bash
npm run test:php -- --filter <the new test>
```

The decision to make once the test exists: **is one-shot approval our
policy, or a gap?** If it is our policy, keep the test, fix the public
write-up, and close this. If it is a gap, the test turns red and the fix
is to make approvals persist.

## Notes for whoever picks this up

Filed during the v1 close-out as F6, from an audit of the "Sync engines
rundown" post against the merged code.

What the audit found. The upstream design this engine was ported from
keeps a per-block approval record, pinned to a fingerprint of the
approved content. Our port dropped that on purpose: a comment at the top
of `includes/engines/de-rtc/class-wp-de-rtc-engine.php` explains that
the machinery existed to protect information stored inside post content,
which our version stores elsewhere. That reasoning is sound for what it
addressed, but the approval record may have been collateral.

Searching for an approval stamp finds nothing. The only related code is
a note saying that a restore happens under the restorer's own
permission, which makes the act of approving safe but says nothing about
whether it lasts.

The place to look is the code that decides which blocks to hold back,
around line 1229 of the same file. It lets a changed block through only
when the block is unchanged from its previous version, or when
WordPress's filter leaves it untouched. Previously-approved content
would fail both of those checks.

If this turns out to be a real gap, the fix belongs in the engine, not
in the merge code that was copied over unchanged. Keeping that copy
untouched is a deliberate rule, and it is worth preserving.
