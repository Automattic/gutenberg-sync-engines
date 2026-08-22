---
id: 0007
title: Work out what presence should do on a slow connection
status: shaping
size: medium
area: awareness
github:
---

# Work out what presence should do on a slow connection

## What happens now

The editor shows who else is in the post, as avatars in the toolbar.
That works the same way for every engine and every connection type, and
the server checks identity so nobody can claim to be someone else.

It was designed for a fast connection. On a slow or intermittent one we
do not know how it behaves, and we have said publicly that we are
working on something better suited to that case. Nothing in this repo
reflects that work, so either it lives somewhere else or it has not
started.

The visible risk is that presence goes stale in both directions. Someone
who closed their laptop still appears to be here, so a colleague waits
for them. Or someone who is really here disappears for a while, so a
colleague assumes the post is free and starts editing.

Presence is also chatty by nature. It sends small updates often. On a
connection where every request is expensive, that is exactly the traffic
you want to shed first, and it is also the least important traffic, since
nobody loses work when an avatar is late.

## Example

1. Open the same post in two windows as two different people.
2. In one window, use the browser's tools to throttle the connection to
   something slow and lossy.
3. Close that window without signing out.
4. Watch the other window.

**What we need to find out:** how long the closed person keeps appearing
present, and whether the answer is acceptable. There is a thirty second
timeout on the server, but we have not confirmed what a person actually
experiences on a bad connection.

## What should happen instead

We do not know yet. That is what this item is for. A reasonable target,
to be argued with: presence should degrade honestly. If we are not sure
someone is still here, the interface should say so rather than pick a
confident answer.

## How we will know it is done

The decision missing here is the most basic one: **is there a problem?**
We have a public claim that this needs work and no evidence either way.

So this item is done when it has been replaced by real items, or closed
as a non-problem. That means answering:

- What does presence do today on a slow connection? Measure it.
- Which part is wrong: the timing, the traffic cost, or what the person
  sees?
- Does the fix belong in this plugin, or in the shared Gutenberg code
  the plugin plugs into? If it belongs in the shared code, it needs
  review before we start, because we carry a copy of that code and
  changes to it are harder to keep.

## Notes for whoever picks this up

Filed during the v1 close-out as F8, from the "Sync engines rundown"
post, which says "we are working to develop new primitives that might be
more appropriate for high-latency connections."

**Start by finding that work.** It may be Alec's, it may be a
conversation rather than code, and it may be that the sentence
described an intention. Whatever the answer, write it here, because the
next person will otherwise repeat this search.

The measurement in the example is worth doing regardless of what turns
up. It is an afternoon, it needs nobody else, and it converts an
open-ended worry into either a real bug or a confirmed non-problem.

Presence data rides the same connection as edits for every engine, so
the answer here interacts with
[0006](0006-a-cheaper-way-to-use-websockets.md): if a connection ends up
carrying only short notices, presence is the obvious thing to move onto
it.
