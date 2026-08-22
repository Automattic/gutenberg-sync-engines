---
id: 0003
title: Two people can stop agreeing on the post when requests fail
status: ready
size: medium
area: de-rtc
github:
---

# Two people can stop agreeing on the post when requests fail

## What happens now

Two people edit the same post on a flaky connection, where some requests
fail or time out. Normally the editor retries and everyone catches up
within a second or two.

Sometimes they do not catch up. Twenty seconds after the last edit, the
two browsers still show different versions of the post, and they stay
that way. Neither person is warned, so whoever saves next quietly
decides what the post says.

This has only been seen with the de-rtc engine over HTTP. The same test
over a websocket connection has never failed.

## Example

1. Start the tests environment.
2. Run the replay, which drops and delays requests on purpose:

```bash
npm run fuzz -- --combos=de-rtc/http-polling --seed-list=5 --steps=12 --trace=retain-on-failure
```

**What you see:** the run fails with a message saying the two browsers
did not end up with the same content within twenty seconds. The post has
eight blocks, one of them a paragraph containing the word `marker`.

**What you expected:** both browsers agree, the way they do on every
other run.

This one is stubborn: it failed when repeated inside the full sweep, but
passed when replayed on its own. Expect to run it many times.

## What should happen instead

Failed and delayed requests should only slow syncing down. Once the
network recovers, both people should end up with the same post, every
time.

## How we will know it is done

First, we need a version of the example above that fails reliably.
That is most of the work. Once it fails on demand, the fix is done when
it passes twenty times in a row.

## Notes for whoever picks this up

Found by the full automated sweep at the end of the v1 work, filed there
as F2. Like [0002](0002-group-block-breaks-after-reload.md), it also
happens on the older version of the code, so it is not a recent
regression.

**Start by making it reproducible, not by fixing it.** Two attempts have
already been lost to guessing. The run above saves a full trace when it
fails; capture one, and record which requests were dropped and in what
order. The useful question is which specific request failing, at which
specific moment, leaves a client believing it is up to date when it is
not.

A likely shape, worth checking before anything else: de-rtc clients
learn about new versions from a short announcement message that carries
a version number and a fingerprint of the content. A client that already
matches the fingerprint correctly downloads nothing. So a client that
*wrongly* believes it matches would also download nothing, and would
never try again, which fits the symptom of stopping rather than being
slow.

The shrinking tool cannot help until the failure is reliable, since it
works by re-running a shorter version of the same sequence.
