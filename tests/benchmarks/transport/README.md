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
- **Server-side per-request metrics** (community-harness conventions) —
  every `/wp-sync/` request the windows make is tagged with the
  [WordPress/distributed-rtc-performance-testing](https://github.com/WordPress/distributed-rtc-performance-testing)
  harness's headers (`X-RTC-Test`, `X-RTC-Scenario: editing|idle`), so a
  site with this plugin's diagnostics request log (local/development
  wp-env, or the `GUTENBERG_SYNC_ENGINES_DIAGNOSTICS` constant) records
  dispatch wall/CPU time, `db_queries`, `db_time_ms` (needs
  `SAVEQUERIES`), peak memory, and concurrency per request. The benchmark
  clears that log up front and folds per-scenario aggregates into the
  summary (`serverSide`, using the community metric names), making runs
  directly comparable with community-published report tables. Without the
  diagnostics module the tags are inert and `serverSide` is null.
- **Baseline** (community convention) — before the trials, N
  unauthenticated `GET /wp/v2/types` round-trips (client-timed over a
  kept-alive connection) plus N tagged empty RTC polls
  (`scenario=baseline`, `approach=baseline`). The server-side report
  normalizes every scenario against the baseline scenario's mean, which is
  how community tables compare environments.

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
| `engine=`    | `current` | Engine to measure under (`intent-log`/`yjs-server`). |
| `trials=`    | `30`      | Measured token round-trips.                         |
| `warmup=`    | `3`       | Unmeasured leading trials.                          |
| `idle=`      | `30`      | Idle-phase seconds (`0` skips the phase).           |
| `baseline=`  | `10`      | Ambient-overhead samples (`0` skips the phase).     |
| `json=`      | —         | Write full results (per-trial data included) here.  |
| `headed=1`   | —         | Visible browser, for debugging.                     |

Two caveats on the server-side lane. The scenario tagging intercepts
`/wp-sync/` HTTP requests through Playwright routing, adding a small
per-request overhead inside the measured latency — identical across
phases, but latency comparisons against pre-tagging runs carry that delta
(single-digit ms; negligible at polling cadence). And it covers the HTTP
transports only: websocket data frames bypass both the tagging and the
REST dispatch the request log measures, so under `transport=websocket`
the `serverSide` aggregates hold just the baseline polls.

Environment: `WP_BASE_URL` (default `http://localhost:8889`, the wp-env
tests site), `WP_USERNAME`/`WP_PASSWORD` (default `admin`/`password`). The
collaboration option is enabled automatically if it isn't already. Both
browser windows run on this machine — don't compare absolute numbers across
machines without noting the environment, and keep the machine otherwise idle
during a run.

### The websocket transport

The websocket transport needs the sync-server daemon running on an address
the *browser* can reach. For the DEV site, `npm run rtc:ws` is the
one-command start (it selects the websocket transport and runs the daemon
with its port published), and the dev env's afterStart hook keeps a daemon
running anyway. This benchmark targets the TESTS env
(`.wp-env.tests.json`, its own wp-env project), where the same two pieces
apply manually:

1. The config sets `WP_SYNC_WEBSOCKET_HOST` to `localhost` (already in this
   repo's config) so the announced socket URL shares the site's cookie
   domain — the daemon authenticates the browser's `logged_in` cookie, and
   cookies for `localhost:<port>` are not sent to the default `127.0.0.1`.
2. wp-env cannot publish extra container ports itself, but its generated
   compose file can. Start the daemon with the port published AND bound to
   `0.0.0.0` (a loopback-bound daemon is unreachable even through a
   published port). The tests env's work dir carries a `-tests-` segment
   and its single environment's WP-CLI service is `cli`:

   ```bash
   docker compose \
       -f "$(ls -d ~/.wp-env/wp-env-*$(basename "$PWD")*-tests-*)/docker-compose.yml" \
       run --rm -p 8787:8787 cli \
       wp collaboration sync-server --host=0.0.0.0 --port=8787
   ```

   (Stop the dev env's auto-started daemon first if it holds port 8787:
   `docker rm -f wp-sync-ws-daemon`. `curl localhost:8787/health` from the
   HOST should answer `OK` — if it doesn't, the browser can't connect
   either and clients retry silently with no visible error.)

The benchmark then works with `transport=websocket`: it counts WebSocket
frames/bytes instead of HTTP requests, and fails with a clear message when
the daemon is unreachable (window B never receives the anchor paragraph).

Known caveat: as of 2026-08-11 the **intent-log engine mangles live typing
over the websocket transport** (characters drop/reorder in the author's own
window — the per-keystroke frame cadence exposes a client-session race that
the HTTP transports' ~1 s batching masks). Benchmark the websocket transport
under `engine=yjs-server` until that is fixed.

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
- **websocket**: true push — observed p50 ≈ 30 ms edit-to-visible (~20×
  better than long-polling, ~60× better than polling) with the lowest idle
  wire volume by far (~14 frames/idle-30 s per window vs ~28–49 HTTP
  requests). The price is the heaviest hosting ask: a persistent daemon,
  TLS termination, and an exposed port.

Latency includes the engine's client-side apply path, so cross-engine runs
of this benchmark differ for engine reasons too — compare transports under
one engine at a time, and use the engine benchmark for engine-vs-engine
server cost.

## The N-window soak (moved)

The soak — the hour-scale multi-window validation run that used to live
here as `soak-transport.mjs` — is a debugging and analysis tool, not a
benchmark, and now lives at `tests/debugging/soak-transport.mjs` with
its documentation in `tests/debugging/README.md`. It still imports this
directory's `lib.mjs`, so both use identical counters, tagging, and
server-log collection.
