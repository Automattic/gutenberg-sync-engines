# Transports

Transports are a separate axis from engines: the engine decides how
concurrent edits merge, the transport decides how updates move. Engines
run over any transport. Run the transport benchmark
(`tests/benchmarks/transport/`) for measured edit-to-visible latency and
idle traffic on your hardware; the stable shape:

| | edit-to-visible latency | idle traffic per collaborator |
| --- | --- | --- |
| http-polling | seconds-scale (bounded below by the poll interval) | roughly one request per poll interval |
| http-long-polling | sub-second (held requests wake on new rows and awareness heartbeats) | more requests than plain polling, each holding a PHP worker up to its wait budget |
| sse | pushed after Redis announces stored changes | one held PHP worker per stream, plus Redis; periodic reconnects |
| websocket | tens of milliseconds | a few frames per heartbeat — plus a persistent daemon, TLS termination, and an exposed port |

**Short polling is the base transport, and an advisory channel sits
beside it.** Every tab editing a post also opens a channel to the other
tabs on that post: by default browser to browser (WebRTC, negotiated
through the heartbeat WordPress already sends from every editor screen),
or, when the site chooses `websocket-advisory`, one socket per tab to the
sync daemon, which relays between the tabs in a room and reaches tabs
that cannot connect to each other directly. Either way the channel
carries presence and the sentence "I landed rows, go and poll", never
content; every read and write stays on the REST sync endpoint. While
every known peer is reachable over it, a tab polls only when it has
something to send, when a peer announces, or when the heartbeat reports
changes from a writer not on the channel. A tab that is alone schedules
no polls and holds its edits until
company arrives, a save (flushed through the room first), or the tab
going hidden. Any tab that cannot reach a peer keeps the cadence in the
table. The transport an admin selects is a preference: long polling
and websocket carry everything while connected and turn the channel off
meanwhile, and short polling is always the fallback. The websocket
transport hands its rooms to short polling whenever its socket is down
and takes them back, at the cursor polling reached, when it reopens. The
reasoning, the rules, and the failure cases are in
[plan/advisory-channel.md](plan/advisory-channel.md).

**What happens to unsaved changes when the last editor leaves** is a
setting (Settings → Collaboration → Unsaved changes), applied above the
engine choice. By default they are discarded: every tab tells the server
when it leaves (a beacon on `pagehide`, or the socket closing), and a
per-post room nobody is in is reset to the saved post, at once when the
last tab leaves or when a new tab arrives and finds nobody there. Every
room response carries a generation token so a tab whose room was reset
under it starts over. The alternative keeps rooms as a shared working
copy. See [plan/room-lifetime.md](plan/room-lifetime.md).

Transport latency is engine-independent (the HTTP rows replicate within
noise under intent-log). One caveat on the axis itself: "engines run
over any transport" is an inherited framework property, not a
principle. It fits the log-shaped engines; for DE-RTC it is part of the
adaptation under review ([architecture-decisions.md](architecture-decisions.md),
item 3) — that engine is allowed to declare its own transport story,
including "manual sync with long delays," without penalty.

The short-polling cadence is tunable: the "Polling interval" field on
Settings → Collaboration (default 5 seconds) slows active-tab polling down to 25 seconds
for hosts that want fewer requests (see
`src/providers/http-polling/README.md` for the exact semantics).

Two websocket specifics. The one-time auth token rides the
`Sec-WebSocket-Protocol` offer list rather than the URL query string,
because query strings end up in server and proxy access logs. And
plaintext `ws://` must never leave a dev box; terminating TLS in front
of the daemon is the operator's job, and the `wss://` address goes in
the "WebSocket transport server" field on Settings → Collaboration (or
the `wp_sync_websocket_url` filter, which wins). The advisory channel
has its own "WebSocket advisory server" field, for a relay; empty means
the daemon.

The advisory channel's websocket link can end at a server that is not
the plugin's daemon. With a `WP_SYNC_WEBSOCKET_ACCESS_TOKEN_SECRET`
configured, each tab carries a signed, two-minute access token (a JSON Web
Token, HS256) that a relay checks with the shared secret and no call
to WordPress; `examples/advisory-relay/` is a Node relay a host can run
as is or port, and `docs/plan/advisory-channel.md` ("Bring your own
relay") lists the access token claims and the message formats. The daemon
accepts access tokens too. The websocket *transport* cannot be relayed this
way: it does engine work and writes rows.

The websocket-only e2e suite runs against
the real transport: it selects the websocket transport on the tests
site, publishes the `wp collaboration sync-server` daemon, and restores
the previous transport at teardown (`npm run test:e2e:websocket`). For
hour-scale per-user costs with a convergence gate, run the soak harness
(`tests/debugging/soak-transport.mjs`).

## Server-sent events with Redis

Select **Server-sent events (Redis)** in Settings → Collaboration. This
transport runs through ordinary WordPress REST requests. It needs no sync
daemon or PHP Redis extension. Each open receive stream occupies a PHP web
worker; Redis removes frequent database checks, not that worker requirement.

For local use, run `npm run env start` or `npm run env:tests start`.
Each config's `afterStart` hook starts Redis, connects it to that environment's
network as `sync-redis`, and waits until it responds. Both configs set
`WP_SYNC_SSE_REDIS_URL` to `redis://sync-redis:6379`. No separate launcher or
Compose file is needed. Redis has no public port and stores no persistent data.
A Redis that fails to start does not fail the environment start (the other
transports need no Redis); the hook prints a notice, and `npm run doctor`
reports the Redis container for each environment.

The hooks call shared npm commands. `redis:project` reads wp-env's project name,
which already identifies the checkout and config. Shell variables `REDIS_PROJECT`
and `REDIS_NAME` reuse that name for the network and Redis container. Set
`GSE_WP_ENV_CONFIG=.wp-env.tests.json` for the tests config; the default is dev.

Use `npm run env:stop` or `npm run env:tests:stop` to stop Redis and WordPress.
This wp-env version has no stop lifecycle hook: plain `wp-env stop` (including
`npm run env stop`) does not stop Redis. Redis uses Docker's `--rm`, so stopping
it also removes its disposable container; the next start creates it again.
`afterDestroy` removes the matching Redis container if it still exists.
The dev config keeps its existing WebSocket daemon startup.
Other transports do not require Redis to be running.

Redis Pub/Sub channels are namespaced by the database host and name, table
prefix, and multisite blog ID, followed by the room name. Installations with
different databases can share one Redis instance without receiving each other's
notices, and one site reached through several hostnames still shares one
channel. Redis carries only notices; document storage stays in WordPress.

On a host, set `WP_SYNC_SSE_REDIS_URL` (or the `wp_sync_sse_redis_url` filter)
to a private Redis address. The settings screen says so next to the choice
when no address is configured; selecting it then runs on polling. `redis://user:password@host:6379` supports Redis ACL
credentials; `rediss://` uses TLS. Keep this value server-side. Configure the
web server and proxy to stream `text/event-stream` without buffering or
compression. The route sends `X-Accel-Buffering: no` and `Cache-Control:
no-cache, no-store, no-transform`.

The browser opens a POST stream with normal WordPress cookies and REST nonce
headers. It shares one stream across its current rooms. The server subscribes
to each Redis channel **before** reading stored updates. Edits, presence, and
room resets queue notices from the table storage; notices publish after the
writer finishes. Redis never stores document content. A replacement storage
must emit `gutenberg_sync_engines_room_changed` after its own successful writes
to get prompt notifications.

Streams send JSON room responses in `sync` events, with a comment heartbeat
at least every five seconds while waiting. They end after at most five
minutes (`wp_sync_sse_max_seconds` can shorten this), then the browser
reauthorizes and resumes from its last applied room cursors. A storage catch-up
read every twenty seconds, within the same request,
also covers a process killed after a database write but before its
Redis publish. Redis restart, deploy, and truncated SSE events cannot remove
stored edits. A disconnected browser's unsent edits retain the existing
engine recovery rules; a page reload can still lose unsent local edits.

Local edits close the receive stream, use the normal `/updates` request, and
resume the stream after that response is applied. This prevents overlapping
responses from moving a room's cursor backward. Redis failures switch receiving
to polling; the browser retries SSE after five seconds, and each further
failure in a row doubles that wait, up to one minute. A tab alone in its room
closes its stream once the discovery window after load passes, exactly as the
other HTTP transports go quiet, so an idle solo tab holds no PHP worker; the
heartbeat's company report reopens it. Every twenty seconds the stream
refreshes presence only for a client still
listed in the room, using its current state. It does not recreate an entry
removed by a leave or room reset. Five-second heartbeat comments reset the
browser's twenty-five-second inactivity timeout; a silent connection is aborted.

The server shortens the stream to five seconds below a positive PHP execution
limit. This is a conservative cap; PHP execution time is not always elapsed
time. A host's PHP-FPM or proxy timeout can end the request earlier, and the
browser reconnects with a fresh storage read.

Benchmark on the test site's actual port (wp-env may choose another):

```sh
WP_BASE_URL=http://localhost:8889 npm run bench -- --suite=transport --transport=sse --engine=intent-log --trials=30 --json=/tmp/sse.json
```

The transport and host benchmarks count SSE response bytes as they arrive,
including streams that later get interrupted. Reports distinguish successful
SSE streams from attempted requests and polling fallback. Server request
metrics recorded at dispatch do not include the later stream wait; use the
host benchmark's whole-request measurements for PHP occupancy. Short runs can
end before a held request is logged at shutdown.

Add `--recovery` to the transport benchmark to interrupt the receiving tab,
accept an edit while it is offline, and require it to catch up without a reload.
The JSON report includes the recovery time separately from normal edit latency.
