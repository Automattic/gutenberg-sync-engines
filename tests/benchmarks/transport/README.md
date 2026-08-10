# Transport experience benchmark

Measures what a **transport** choice does to the collaboration experience and
to the hosting bill, using two real browser clients against a live site. It
complements the engine benchmark one directory up: that harness times the
server engine seam in-process; this one measures the end-to-end path the
engine harness deliberately excludes — polling cadence, request volume, and
edit-to-visible latency as a user experiences it.

## What it measures

- **Edit-to-visible latency** — window A inserts a unique token; both windows
  stamp `Date.now()` *inside the page* (a `wp.data.subscribe` watcher) when
  the token appears in their block store. Latency is B's arrival stamp minus
  A's local-echo stamp: same machine, same clock, no automation IPC skew.
  Trials are spaced with deterministic jitter so arrivals sample the polling
  phase instead of locking to it. Reported as min/p50/p90/max/mean over the
  measured trials.
- **Wire traffic per collaborator** — every `/wp-sync/v1/` request (count,
  request/response body bytes) or WebSocket frame per window, during two
  phases: *editing* (the trial loop) and *idle* (both windows open, nobody
  typing). The idle phase is the steady-state carrying cost per collaborator
  that hosts should size for. Byte counts are message bodies only; HTTP
  headers add roughly another 0.5–1 KB per request on top.
- **Observed transport** — self-labeled from the traffic actually seen
  (websocket frames / `/long-poll` / `/updates`), and compared against the
  requested transport. A mismatch usually means a
  `WP_COLLABORATION_TRANSPORT` constant/env override on the site, or a
  failed negotiation.

## Running it

Needs a running environment with the Gutenberg subtree and this plugin
active (see the repo README), and `npx playwright install chromium` done
once. Then, from the repo root:

```bash
node tests/benchmarks/transport/benchmark-transport.mjs \
    transport=http-polling trials=30 json=polling.json

node tests/benchmarks/transport/benchmark-transport.mjs \
    transport=http-long-polling trials=30 json=long-polling.json
```

Arguments are bare `key=value` tokens (the engine benchmark's convention):

| Argument     | Default   | Meaning                                             |
| ------------ | --------- | --------------------------------------------------- |
| `transport=` | `current` | Transport to measure; switched via the Settings →   |
|              |           | Collaboration screen and restored afterwards.       |
| `engine=`    | `current` | Engine to measure under (`intent-log`/`yjs-relay`). |
| `trials=`    | `30`      | Measured token round-trips.                         |
| `warmup=`    | `3`       | Unmeasured leading trials.                          |
| `idle=`      | `30`      | Idle-phase seconds (`0` skips the phase).           |
| `json=`      | —         | Write full results (per-trial data included) here.  |
| `headed=1`   | —         | Visible browser, for debugging.                     |

Environment: `WP_BASE_URL` (default `http://localhost:8889`, the wp-env
tests site), `WP_USERNAME`/`WP_PASSWORD` (default `admin`/`password`). The
collaboration option is enabled automatically if it isn't already. Both
browser windows run on this machine — don't compare absolute numbers across
machines without noting the environment, and keep the machine otherwise idle
during a run.

### The websocket transport

The websocket transport needs the sync-server daemon running on an address
the *browser* can reach (`WP_SYNC_WEBSOCKET_HOST`/`WP_SYNC_WEBSOCKET_PORT`,
default `ws://127.0.0.1:8787`). Under wp-env the daemon would run inside the
container, where its port is not mapped to the host — so benchmarking the
websocket transport requires a host-reachable daemon (e.g. a non-container
WordPress install, or a container setup that maps the port). The benchmark
counts WebSocket frames/bytes and will fail with a clear message when the
daemon is unreachable (window B never receives the anchor paragraph).

## Reading the numbers

- **http-polling**: latency has *two* polling legs — the edit waits in A's
  queue until A's next poll sends it, then B receives it on B's next poll —
  so with the 1 s with-collaborators cadence expect a p50 around one full
  interval (~1–1.5 s) and a max near two. Requests continue at the same
  cadence while idle; that idle request rate × collaborators is the host's
  steady-state load.
- **http-long-polling**: receive latency drops to near-push (the server
  re-checks storage every 500 ms while holding the request), so expect a p50
  in the hundreds of milliseconds. The cost moves server-side: each held
  request occupies a PHP worker for up to its wait budget (default 20 s) —
  the *request count* here understates worker occupancy; see the capacity
  warning in `includes/transports/class-wp-http-long-polling-sync-server.php`.
  Note also that held requests wake on awareness changes, and with
  collaborators present each client's awareness heartbeat keeps releasing
  the other's held request — so the idle *request rate* can exceed
  short-polling's (observed ~94 vs ~56 requests/min per window) even though
  each request is short-lived.
- **websocket**: push latency (no polling floor) and the lowest wire volume,
  in exchange for the heaviest hosting ask (persistent daemon, TLS
  termination, exposed port).

Latency includes the engine's client-side apply path, so cross-engine runs
of this benchmark differ for engine reasons too — compare transports under
one engine at a time, and use the engine benchmark for engine-vs-engine
server cost.
