---
id: 0004
title: Remove the older way of sending Adopt and Reject decisions
status: shaping
size: small
area: de-rtc
github:
---

# Remove the older way of sending Adopt and Reject decisions

## What happens now

When someone resolves a held-back edit by clicking Adopt or Reject, that
decision now travels over its own web address on the server. That is the
good path, and it is what current browsers use.

Older browsers running the previous version of this plugin send the
decision a different way, folded in with the ordinary sync messages. The
server still accepts that older way on purpose, so that people who have
not reloaded their editor keep working.

Nobody using WordPress can tell the difference. The cost is ours: two
pieces of code do the same job, so every future change to how decisions
work has to be made in both places, or deliberately made in one.

The second cost is subtler and already bit us once. The current browser
code falls back to the old way if the new one fails. That means a
completely broken new route would look fine in testing, because the
fallback quietly covers for it. We had to add a test whose only job is
to prove the new route really ran.

## Example

1. Open a post where a held-back edit is waiting for review.
2. Open the browser's network tab.
3. Click Adopt.

**What you see:** a request to `/wp-sync/v1/de-rtc/resolve`. That is the
current path. If you block that address and click Adopt again, the
decision still goes through, carried inside the next ordinary sync
request instead. Both work. That is the duplication.

## What should happen instead

One way to send a decision. The server stops accepting the old way, and
the browser stops carrying the fallback code.

## How we will know it is done

The decision to make first: **when is it safe?** Removing the old path
breaks any editor tab that has been open since before the change. Pick
one of these and write it down here:

- at the next release, accepting that stale tabs get an error
- after a release where the old path logs a warning, so we can see if
  anyone still uses it
- never, and delete this item

Once that is decided, the work is done when the server rejects the old
message, the browser has no fallback, and the tests that pinned both
paths are updated to expect one.

## Notes for whoever picks this up

Filed during the v1 close-out as F4, from the accepted proposal in
`proposals/b5.md`, which deliberately kept the old path and said its
removal should be its own item.

The pieces: the shared code that applies a decision lives in
`includes/engines/de-rtc/class-wp-de-rtc-engine.php`; the newer route is
in `class-wp-de-rtc-review-controller.php`; the browser's fallback is in
`src/engines/de-rtc/review.ts`.

Note that the newer route is only used for posts and pages, matching
where de-rtc saves through the normal save endpoint. Everything else
still uses the old path as its only path, so "remove the old way" also
means deciding what those types do. That is part of the decision above,
and it is the reason this is not simply a delete.
