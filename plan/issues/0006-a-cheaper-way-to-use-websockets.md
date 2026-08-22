---
id: 0006
title: A cheaper way to use websockets, where the server only says "there is news"
status: shaping
size: large
area: transports
github:
---

# A cheaper way to use websockets, where the server only says "there is news"

## What happens now

There are three ways for the editor to find out that someone else
changed the post.

The default is to ask the server every second or so. It works on any
host, and updates arrive within a couple of seconds.

Websockets keep a connection open, so updates arrive in tens of
milliseconds. The catch is what our websocket server has to do. Every
message goes through a single long-running PHP process that has loaded
all of WordPress. That process does the real merging work, one message
at a time. One slow merge holds up every person in every post, and if
the process dies, collaboration stops for everyone until someone
restarts it.

There is a third option we have described publicly but not built. The
open connection carries no edits at all. It only says "there is
something new for you". The editor then fetches the change the ordinary
way, through normal WordPress requests handled by the normal pool of
PHP workers.

That would keep the fast notification and give back the ability to
absorb load, since the notifier does almost nothing per message and a
crash costs speed rather than function.

## Example

Today, with websockets, a person's typing travels like this:

1. Person A types.
2. The edit goes to the always-on server process.
3. That process merges it, holding up every other message meanwhile.
4. The merged result goes out to person B.

Under the proposal it would be:

1. Person A types.
2. The edit goes to WordPress as an ordinary request, like polling.
3. The always-on process sends person B a short note: "there is news."
4. Person B fetches the change as an ordinary request.

**What we expect:** roughly the same speed for person B, much less work
in the one process everybody shares.

**What we do not know:** whether the extra round trip in step 4 eats the
speed advantage, and whether the notification traffic costs more than it
saves.

## What should happen instead

We should be able to offer websocket speed without asking a host to run
a process that does the merging for everyone.

## How we will know it is done

Two decisions come before any code:

- **What does the note say?** Just "check the post", or "the post is now
  at version N"? Anything carrying a version number is a change to the
  message format we send, which we treat as a bigger decision needing
  review.
- **Is it a fourth option, or a mode of the existing websocket one?**

Once built, it is done when the automated sweep passes on the new path
for every engine, and when the transport measurement shows the numbers
that justified it:

```bash
node tests/benchmarks/transport/benchmark-transport.mjs engine=de-rtc transport=<the new one>
```

Compare against the same run on the current websocket option. We are
looking for similar time-to-visible, and clearly less work in the
always-on process.

## Notes for whoever picks this up

Filed during the v1 close-out as F7, from the "Sync engines rundown"
post, which describes this as something we are exploring. Nothing in the
repo implements it.

Two things already exist that make this easier than it sounds. The
websocket server already checks storage once a second for changes it did
not see over the socket, which is most of the machinery for noticing
that news exists. And de-rtc already works this way in spirit: since the
announce change, its socket carries only short notices, and the actual
content travels over ordinary save and fetch requests. Building this for
de-rtc first is mostly deleting the parts of the socket path it no
longer needs.

The polling interval is now a setting, which matters here. The fallback
path's speed is tunable, so the comparison should be run at more than
one interval.

The intent-log engine is the hard case, not de-rtc, because its updates
are small and frequent and it takes a lock while merging. That lock is
exactly the thing that stalls the shared process today, so it is also
where this change would help most.
