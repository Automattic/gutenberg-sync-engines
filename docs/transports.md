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
