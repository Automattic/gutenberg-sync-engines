# Transports

Transports are a separate axis from engines: the engine decides how
concurrent edits merge, the transport decides how updates move. Engines
run over any transport. Run the transport benchmark
(`tests/benchmarks/transport/`) for measured edit-to-visible latency and
idle traffic on your hardware; the stable shape:

| | edit-to-visible latency | idle traffic per collaborator |
| --- | --- | --- |
| http-polling | seconds-scale (bounded below by the poll interval) | roughly one request per poll interval |
| sse | pushed the moment a row lands (Redis notices), or within half a second (version checks) | one held PHP worker per stream for up to five minutes; without Redis, one small lookup twice a second per stream; needs a proxy that passes streams through |
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
table. The transport an admin selects is a preference: SSE and
websocket carry everything while connected and turn the channel off
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

## Server-sent events

Select **Server-sent events** in Settings → Collaboration. This transport
runs through ordinary WordPress REST requests: the browser opens one
long-lived response per tab and the server writes each change to it. It
needs no sync daemon or PHP Redis extension. Each open receive stream
occupies a PHP web worker for its whole length.

Redis is optional. What a stream sleeps on is chosen per request, in this
order:

| Wait | Chosen when | Cost per open stream while idle |
| --- | --- | --- |
| Redis Pub/Sub notice | `WP_SYNC_SSE_REDIS_URL` is set, or a Redis object cache is in use (its `WP_REDIS_*` constants name the server) | nothing: the stream is written the moment a row lands |
| Version counter in the object cache | a persistent object cache of any kind (Memcached included) | one memory read twice a second |
| Version counter in the room-meta table | no cache at all | one indexed query twice a second |

The version counter is a number the room storage bumps after every
successful write (updates, presence, room meta, a reset) with an atomic
increment, so two writers can never lose each other's bump. The stream
reads the counters of all its rooms in one lookup and re-reads storage
when any differs from the snapshot it took just before its last read; a
write landing during that read therefore still wakes the next check. The
snapshot is compared for change, not counted, so nothing depends on the
exact value. A storage other than the plugin's tables (through the
storage filter) has no counters, and the stream checks it the long way
instead: rows past the cursor and the awareness map, per room, twice a
second.

A configured Redis that does not answer is reported through the
`gutenberg_sync_engines_sse_redis_failed` action and the stream falls back
to the version checks; the browser never has to fall back to polling for
it. The `wp_sync_sse_redis_url` filter sees the configured or detected
address and may replace it, or return an empty string to keep the
transport off Redis. Detection skips a Redis cluster, replica set, or
sentinel group, which the plugin's small client does not speak; set the
address by hand there if a single endpoint is available.

### Proxies, buffering, and timeouts

A stream only works when every hop between PHP and the browser passes
bytes through as they are written. This is the one operational
requirement SSE adds over short polling, and it is the usual reason a
stream "does not work" on a host where polling does:

- **Response buffering.** The route sends `X-Accel-Buffering: no` (which
  nginx honors, `proxy_buffering` and `fastcgi_buffering` included) and
  `Cache-Control: no-cache, no-store, no-transform`. Other proxies, CDNs,
  and page caches need their own setting to leave `text/event-stream`
  responses unbuffered and uncached.
- **Compression.** Compression buffers. Exclude `text/event-stream` from
  gzip and brotli at the proxy and in Apache's `mod_deflate`; PHP's
  `zlib.output_compression` must be off for the route.
- **Timeouts.** PHP's `max_execution_time` shortens a stream (the server
  ends it five seconds before the limit), and PHP-FPM's
  `request_terminate_timeout`, a proxy's read timeout, or a load
  balancer's idle timeout can end it earlier. Every end is safe: the
  browser reconnects from its last applied cursor. A five-second keepalive
  comment keeps idle-timeout counters from firing on quiet streams.
- **Worker pools.** Size the PHP worker pool for one held worker per open
  editor tab on top of ordinary traffic. Tabs that are alone on a post
  close their stream, so the count is the number of tabs that have
  company.

When a stream cannot be opened at all (the request fails or is refused),
the browser falls back to short polling and retries the stream with a
growing wait, so a misconfigured proxy degrades to polling rather than
breaking editing. A proxy that accepts the stream but buffers it is the
worse case: the browser sees no keepalive, aborts after twenty-five
seconds, and retries. If a host cannot pass streams through, choose
polling with an advisory channel instead.

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

On a host without a Redis object cache, set `WP_SYNC_SSE_REDIS_URL` (or the
`wp_sync_sse_redis_url` filter) to a private Redis address to get the
instant wake; the settings screen says so next to the choice when no Redis
is configured or detected. `redis://user:password@host:6379` supports Redis
ACL credentials, `rediss://` uses TLS, and `unix:///path/to/redis.sock` a
local socket. Keep this value server-side.

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
to polling (only a stream that cannot be opened at all does this; a Redis
outage is handled server-side by the storage checks); the browser retries
SSE after five seconds, and each further failure in a row doubles that
wait, up to one minute. A tab alone in its room
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

The sse-only e2e suite (`npm run test:e2e:sse`) selects this transport on the
tests site for its duration and restores the previous one afterwards. It needs
the Redis container the tests env starts, and refuses to run without it, so
that it certifies the Redis wake rather than the storage checks. The fuzzer
sweeps `sse` with the same rule.
