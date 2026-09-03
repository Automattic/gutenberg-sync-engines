# AGENTS.md

Operational guide for working in this repo. Read this first.

## Language

- IMPORTANT: Write clear, short sentences as if explaining things to a
  less-technical friend. Avoid all technical jargon and self-invented terms.
  Do not use abstract structural metaphors or shorthand arrow chains.
- Practice "BLUF": Bottom Line Up Front. Start with the main point or
  conclusion, then provide supporting details.
- Be as concise as possible without omitting essential information.
- Before posting something for external consumption, run the draft past a fresh
  subagent instructed to flag jargon and follow these language rules. Use a
  model that is skilled at summarizing.

## What this is

`gutenberg-sync-engines` is a WordPress plugin that supplies the pluggable
**engines** (how concurrent edits merge) and **transports** (how updates move)
for Gutenberg's real-time collaboration (RTC) **framework**. The framework
itself lives in Gutenberg core (`@wordpress/sync` client + the
`lib/experimental/collaboration/` server): it is a generic, engine-neutral
substrate — a `createSyncManager` shell, two registries (engines + transports)
with client/server negotiation, the `SyncEngine` SPI, and a shared `Y` export
(`wp.sync.Y`). **Without this plugin active, RTC is disabled** — the framework
registers no engine or transport, so a session finds nothing to negotiate and
the editor falls back to the classic exclusive post lock.

This plugin provides:

- **Engines:** `intent-log` (server-authoritative log of typed intents; merges
  by transform, sets genuine conflicts aside for review), `yjs-server`
  (server-authoritative CRDT: the vendored y-php library merges every update
  into a canonical room document server-side, compacts by itself, and
  materializes post content — lock-free ingest; it inherited the retired
  naive-relay yjs-relay engine's client CRDT machinery and wire format), and
  `de-rtc` (Distributed Editing's save-centric model on the room protocol:
  clients propose whole content against a named base version; the server
  three-way-merges every proposal with the merge core ported verbatim from
  the wordpress-develop `add/distributed-editing` branch and announces
  each accepted version; genuine conflicts escalate instead of silently
  merging).
  The framework's conventional default engine
  (`WP_Sync_Engine_Registry::DEFAULT_ENGINE`) is **intent-log** — that's
  what runs when the `wp_sync_engine` option is unset. Registration order
  only matters when a CONFIGURED slug isn't registered (misconfiguration
  degrades to the first registered engine: yjs-server).
- **Transports:** `http-polling` (default), `http-long-polling`, `websocket`.

It registers through the framework's extension points: PHP `wp_sync_engines` /
`wp_sync_transports` filters; JS `registerSyncEngine` / `registerSyncTransport`
(via `@wordpress/sync`'s unlockable private APIs). The active engine/transport
are chosen on the **Settings → Collaboration** screen (`wp_sync_engine` option +
the `WP_COLLABORATION_TRANSPORT` config value).

The framework/plugin split is complete: the framework ships **neither** engines
**nor** transports; both come solely from here.

## Repo layout

- `gutenberg-sync-engines.php` — plugin entry.
- `includes/` — server PHP: `engines/{intent-log,yjs-server,de-rtc}/`,
  `transports/{...,websocket/}`, `admin/` (the Collaboration settings screen),
  and `lib/`:
  - `engines/de-rtc/merge-core.php` — the DE-RTC merge core, ported
    VERBATIM from the Gutenberg `chriszarate/refreshed-de-rtc` branch's
    `de-rtc.php` (itself a verbatim port of wordpress-develop
    `add/distributed-editing`): the exact call-graph closure (113
    functions) of the engine-facing entry points — serialized-block +
    block-identity three-way merges, the rich-text merge model, update
    construction/validation, version snapshots, sync-meta parse/format,
    canonicalization/hashing. Frozen like the intent-log core and excluded
    from phpcs; the only deltas are the vendored-library path and loader
    delegation (marked `DELTA` in place). Loaded behind a
    `function_exists( 'wp_de_rtc_get_reason_codes' )` guard so a
    Core/Gutenberg build that ships DE-RTC itself wins.
  - `lib/y-php/` — **vendored y-php** (PHP port of Yjs 13.6.31), imported
    verbatim from <https://github.com/alecgeatches/y-php> (MIT; upstream
    commit recorded in the import commit). ONE deliberate local delta,
    preserve it when re-vendoring: `composer.json` pins
    `config.platform.php` to 7.4 (with the lock resolved for it) so the
    suite installs on WP-supported PHP. Excluded from our phpcs (it
    deliberately mirrors JS Yjs style and carries its own configs). Its own
    conformance suite runs in CI:
    `composer --working-dir=includes/lib/y-php install && composer
    --working-dir=includes/lib/y-php test` (~4 s, no WordPress needed).
    Treat it like the frozen intent-log core: don't casually edit — its
    contract is byte-parity with JS Yjs, enforced by translated upstream
    tests + fixtures generated from the real JS implementation.
  - `lib/y-php-loader.php` — lazy runtime loader (PSR-4 autoloader +
    Composer-`files` equivalents) so the plugin can use y-php without a
    Composer autoloader.
  - `lib/automerge-php/` — **vendored automerge-php** (native PHP port of
    Automerge for DE-RTC research), imported verbatim from the Gutenberg
    `chriszarate/refreshed-de-rtc` branch (originally wordpress-develop
    `add/distributed-editing`, PR WordPress/wordpress-develop#12334; MIT,
    PHP 8.2+ with mbstring, namespace
    `WordPress\DistributedEditing\Automerge`, no WordPress dependency).
    Excluded from phpcs; frozen like y-php. Its own conformance suite runs
    in CI: `php includes/lib/automerge-php/tests/run.php` (<1 s, no
    WordPress; 680 mapped upstream tests). FULL parity needs the fixed
    GB11 grapheme rules of PCRE2 ≥ 10.43, which PHP bundles from 8.4 —
    under PHP ≤ 8.3 builds carrying PCRE2 10.42 (stock GitHub runners,
    Ubuntu 24.04 packages) two adjacent emoji-ZWJ sequences count as ONE
    `\X` cluster and exactly 2 of the 680 tests fail (grapheme cursor
    tracking + a UTF-16-boundary splice); CI pins PHP 8.4 for this step.
    The 11 upstream fixture files
    the runner reads live under `automerge-php/upstream/automerge/`
    (fetched from automerge/automerge; pin recorded in
    `VENDORED_FROM_COMMIT.txt` — the source branches referenced an
    upstream submodule that was never committed). The runner leaves the
    tracked `PORTING_STATUS.json` alone by default (a marked `DELTA` in
    `tests/run.php` — upstream rewrote it, timestamp included, on every
    run, dirtying the tree); set `AUTOMERGE_PHP_UPDATE_STATUS=1` to
    refresh it deliberately. NOTE: the DE-RTC
    *shipping* merge path (`native-automerge-blocks-v1`) never calls this
    library — it backs only the dead legacy whole-text lane and
    external-repair; it is vendored for fidelity and future use.
  - `lib/automerge-php-loader.php` — lazy PSR-4 loader shim +
    `gutenberg_sync_engines_automerge_php_is_supported()` (PHP ≥ 8.2 +
    mbstring gate).
- `src/` — client JS/TS (webpack entry `src/index.ts` → `build/sync-engines.js`,
  externalizes `@wordpress/sync`→`wp.sync` and `yjs`→`wp.sync.Y`):
  - `engines/intent-log/` — the **frozen cross-language core** (byte-matched
    against its PHP twin + JSON vectors). Excluded from prettier (eslint
    runs with relaxed rules), but TYPE-CHECKED: the modules are plain
    JavaScript (they run under Node with no build step — the sweep and
    vector generators import them directly) typed through JSDoc against
    the shared interfaces in `engine-types.d.ts`; `tsconfig.json` sets
    `checkJs`, so `npm run typecheck` (CI) checks the core and TypeScript
    consumers get their types from the JSDoc itself. There are NO
    per-module `.d.ts` sidecars — they drifted (a missing `planBatch`
    parameter, undeclared exports) and were removed. A JSDoc edit is the
    one non-behavioral change the core routinely takes. Don't casually
    edit — changes must stay in lockstep with the PHP core and test
    vectors. Its Jest harness lives in `tests/js/engines/intent-log/`, which
    also holds the Node-only pieces that are NOT shipped: the deterministic
    simulator (`simulator.js`, the spec's validation oracle) and the JS
    reference `genesisSyncId` (`genesis-sync-id.js`, on `node:crypto`; the
    editor never mints genesis ids — the server and the build-free stamper
    `includes/engines/intent-log/sync-id.js` do). Both are type-checked
    too (only the `*.test.js` files and Jest setup are excluded). Its
    vector generators are in `tests/tools/`. One file is client-only:
    `client.js` (the replica —
    outbox, optimistic replan, log retention) has no PHP twin and no vector
    coverage, since the server plans with the planner directly. It is still
    core, still frozen-by-default; changes there are additive and covered by
    `tests/js/engines/intent-log/client.test.js`.
  - `engines/yjs/` — the shared Yjs client modules (CRDT doc schema,
    snapshot helpers, `undo.ts`, vendored `y-utilities/` — the latter ignored
    by eslint), inherited from the retired yjs-relay engine and used by
    yjs-server.
  - `providers/{http-polling,http-long-polling,websocket}/` — transports.
  - `framework.ts` — unlocks `@wordpress/sync` private APIs once and re-exports
    the framework runtime the adapters use.
- `gutenberg/` — a **pinned, squashed git subtree of Gutenberg** (source only;
  see below). The BUNDLED runtime framework: the plugin entry loads
  `gutenberg/gutenberg.php` itself whenever no standalone Gutenberg is
  active (wp-env no longer mounts it as a separate plugin).
- `tests/` — ALL tests, fixtures, and test tooling: `tests/phpunit/` (PHPUnit,
  boots via `tests/bootstrap.php`), `tests/js/` (Jest unit tests + setup files,
  mirroring `src/`; `tests/js/engines/intent-log/` is the frozen core's
  harness), `tests/e2e/` (Playwright specs + config; `specs/http-only/` and
  `specs/websocket-only/` are the transport-specific suites relocated from the
  framework, `plugins/` holds the test WebSocket provider fixture plugin,
  `bin/` the y-websocket sync-server daemon + the `rtc:ws`/`rtc:http` dev
  switcher for the real websocket transport; see Testing),
  `tests/benchmarks/` (the BENCHMARKS behind one command, `npm run
  bench` — by default the HOST COST REPORT in `tests/benchmarks/host/`,
  what the plugin adds to a server vs the same site with the plugin
  deactivated; `--suite=engines` is the engine-decision matrix (`wp
  eval-file tests/benchmarks/benchmark.php` per run) and
  `--suite=transport` the browser-driven transport-experience benchmark
  in `tests/benchmarks/transport/`),
  `tests/debugging/` (the debugging/analysis TOOLS, deliberately NOT
  behind `npm run bench` — run directly: the N-window soak
  (`tests/debugging/soak-transport.mjs`) and the
  capture→sanitize→replay session tools in `tests/debugging/replay/` —
  community-harness fixture format; see `tests/debugging/README.md`),
  `tests/fuzzer/` (the seeded browser fuzzer swept across every
  engine × transport combo — `npm run fuzz`; see its README for strategy,
  replay, and triage), and
  `tests/tools/` (Node CLI scripts: vector generators, the simulator sweep,
  the manual two-tab observer). The frozen intent-log vectors exist as TWO
  deliberate copies — `tests/js/engines/intent-log/test-vectors/` (replayed by
  Jest) and `tests/phpunit/test-vectors/` (replayed by PHPUnit) — kept
  byte-identical by `tests/js/engines/intent-log/vector-parity.test.js`;
  regenerate with the `tests/tools/` scripts and always update both.
- `docs/` — the conceptual docs, indexed by `docs/README.md`:
  `engine-comparison.md` (the decision guide: scorecard, parity table,
  resource profiles, per-engine known gaps), `principles.md` (P1-P7),
  `scenarios.md` (the A-G wire narratives), `transports.md`,
  `de-rtc-fidelity.md` (the audit against the upstream vision),
  `architecture-decisions.md`, and `glossary.md` (the project's
  vocabulary in plain words). The set is the interpretation layer over
  both benchmark harnesses; deliberately number-free (run `npm run
  bench` for numbers) — keep the SHAPES current when engine
  capabilities or benchmarks change.
- `docs/plan/` — how we plan work. `README.md` (the rules, the labels, the
  flow), `history.md` (why the code is shaped this way and what has
  already been tried and failed), `wontfix.md` (looked at, set aside,
  with reasons). The work itself lives in GitHub Issues, not here.

## The `gutenberg/` subtree

The plugin needs the exact Gutenberg framework it was built against, so a
Gutenberg checkout is vendored as a **squashed git subtree** pinned to a
specific framework commit. The plugin entry **loads it directly**
(`gutenberg/gutenberg.php`) whenever no standalone Gutenberg plugin is
active — the same bundled-loading path the release zip uses; neither wp-env
config mounts the subtree as its own plugin anymore. A standalone Gutenberg,
when active, always wins (the loader defers; the
`standalone-gutenberg-precedence` e2e spec certifies this with the
`tests/e2e/plugins/gutenberg-stub` fixture, which the tests env mounts at
`wp-content/plugins/gutenberg`). The subtree is committed **source-only** —
its `node_modules/` and `build/` are gitignored (by Gutenberg's own nested
`.gitignore`) and must be generated locally (see Setup).

Bump the pin with a squashed subtree pull from the framework checkout:

```bash
git subtree pull --prefix gutenberg <path-to-gutenberg-framework-checkout> <branch> --squash
```

After a bump, re-run `cd gutenberg && npm install && npm run build`. A subtree
`npm run build` may touch a tracked snapshot (e.g. readable-js-assets); revert
such build side-effects so the subtree stays pristine at the pin.

## Setup (from a clean checkout)

```bash
composer install          # PHP tooling (PHPCS/WPCS, PHPUnit 9 + polyfills)
npm install               # JS tooling (@wordpress/scripts, wp-env, Playwright)
npm run build             # This plugin's client bundle → build/sync-engines.js

# Build the vendored Gutenberg once (source-only in git). Heavy (~1-2 min build,
# plus a large npm install). Required for wp-env to serve working editor assets
# AND for Jest/typecheck, which resolve @wordpress/sync + yjs from the subtree.
# --ignore-scripts skips Gutenberg's `prepare` hook (`husky install`), which
# errors out inside a subtree (no .git at the subtree root; husky 7.0.0 has no
# HUSKY=0 skip). The lifecycle scripts it also skips (icons library, blocks
# manifests) are regenerated by `npm run build`, so nothing is lost.
cd gutenberg && npm install --ignore-scripts && npm run build && cd ..
```

## Environment

Two SEPARATE wp-env configs (the split the env 11 deprecation asks for; both
set `testsEnvironment: false`, so each starts a single site):

```bash
npm run env start         # DEV env (.wp-env.json): this plugin (which loads
                          # the bundled Gutenberg subtree itself),
                          # http://localhost:8888. Its afterStart
                          # lifecycle hook auto-starts the websocket sync
                          # daemon (detached, --mode=daemon: the site's
                          # transport selection is NOT touched).
npm run env:tests start   # TESTS env (.wp-env.tests.json): same mounts,
                          # http://localhost:8889, no lifecycle hook. This is
                          # what test:php / test:e2e / CI target.
npm run env stop          # (env:tests stop for the tests env)
```

`autoPort` is on, so when a port is busy wp-env picks a free one and prints
the URL it chose. Force ports with `WP_ENV_PORT`. Each config has its own
work dir under `~/.wp-env` (the tests one carries a `-tests-` segment), so
the two environments are fully independent — separate databases included.
Personal overrides go in `.wp-env.override.json` /
`.wp-env.tests.override.json` (both gitignored).

## Testing

```bash
npm run test:js             # Jest: engines/providers + frozen-core vectors
npm run test:php            # PHPUnit in the wp-env tests container
npm run test:e2e            # Playwright: two-browser collaboration (+ http-only)
npm run test:e2e:websocket  # Playwright: websocket-only suite (test WS provider
                            # plugin + y-websocket daemon, auto-started)
```

**Iterate at the cheapest layer that can catch the change.** The ladder,
fast → slow (only the last three need wp-env):

1. **Intent-log simulator sweep** — `node tests/tools/sweep.js [seeds]
   [steps] [clients]` (defaults 60/400/3; deterministic, sub-second at
   small sizes, no WordPress). First stop for any intent-log
   planner/merge-behavior change: fails loudly on oracle violations and
   prints disposition/escalation stats so drift is visible.
2. **Jest + frozen vectors** — `npm run test:js` (needs only the built
   subtree). Engines, providers, and the cross-language vector contract.
3. **Vendored conformance suites** — y-php (~4 s) and automerge-php
   (<1 s), commands above; no WordPress. Only when touching the vendored
   libs (rare — they're frozen).
4. **PHPUnit** — `npm run test:php`. Server engines, transports, storage.
5. **e2e** — `npm run test:e2e` (minutes, browser collaboration).
6. **Fuzzer** — `npm run fuzz:quick` as a post-change smoke (all engines
   over http-polling, 2 seeds each, faults/reloads off — a few minutes
   against the running tests env); the full `npm run fuzz` matrix for
   real bug hunting (see `tests/fuzzer/README.md`).

Single-test loops — don't rerun a whole suite while iterating on one
failure:

```bash
npm run test:js -- sync-id                    # Jest files matching a pattern
npm run test:js -- -t 'name substring'        # single Jest test by name
npm run test:php -- --filter Test_Class_Name  # single PHPUnit class/method
npm run test:e2e -- collaboration-intent-log  # single e2e spec by filename
```

Never run `test:php` while an e2e run is in flight against the same env:
PHPUnit wipes the tests-env database, killing every in-flight spec
(auth and plugin activation vanish mid-run). Serialize the suites.

`test:js` and `npm run typecheck` resolve `@wordpress/sync`/`yjs` from the
**built subtree** (see Setup); `WP_SYNC_FRAMEWORK_ROOT=<framework-checkout>`
points Jest at a live framework checkout instead when co-developing (tsconfig
paths stay pinned to the subtree).

`test:php` and `test:e2e` need the running TESTS env (`npm run env:tests
start`) with the subtree built; both target `.wp-env.tests.json` (test:php
runs PHPUnit in that env's cli container, Playwright's webServer starts that
env when 8889 is not already serving). For e2e also run `npx playwright install chromium` once. If the
tests site isn't on `:8889` (auto-port / override), pass
`WP_BASE_URL=http://localhost:<tests-port>`. Beware: if ANOTHER project's
wp-env holds `:8889`, Playwright's webServer check sees the port alive and
silently reuses that foreign site (wp-env credentials are identical
everywhere, so auth even succeeds); the first visible failure is a
global-setup REST call dying with
"Unexpected end of JSON input". Always pass `WP_BASE_URL` in that case
(`npm run doctor` detects this arrangement and prints the right URL).

All suites are green at head; CI (`.github/workflows/ci.yml`) is the
source of truth for exact test counts — it certifies every suite
(including `composer lint`, the websocket e2e lane, and the subtree's
collaboration-review-panel component Jest) on pushes to `main` and
PRs. The v1 integration tree passed the full default e2e suite three
consecutive times with retries disabled; the old login
flake is closed by the plugin-local hardened fixtures
(`tests/e2e/config/collaboration-fixtures.ts` — the root-cause subtree
fixture fix remains upstream/human-owned). One known intermittent
remains: the parked-A12 residual (intent-log mid-burst compaction
splice, issue #37), firing ~1-2 of 8 under the repetition hammer; the
e2e CI job keeps the base config's 2-retries-in-CI to absorb it. The
vendored libraries' own
conformance suites run separately:
y-php (`composer --working-dir=includes/lib/y-php test`) and
automerge-php (`php includes/lib/automerge-php/tests/run.php`).

The transport-specific e2e suites live here (relocated from the framework):
`tests/e2e/specs/http-only/` runs in the default suite; `tests/e2e/specs/
websocket-only/` runs only under `test:e2e:websocket`, which since the
V1 A3 rework runs against the plugin's REAL websocket transport:
`playwright.rtc-websocket.config.ts` launches
`tests/e2e/bin/rtc-real-ws-daemon.mjs` as a second webServer, which
selects the websocket transport on the tests site, publishes the
`wp collaboration sync-server` PHP daemon from the tests env's cli
image on host port 8787 (health-checked on the daemon's own /health),
and restores the previous transport at teardown. No spec is skipped.
(The old y-websocket PEER-relay fixture lane — the test WS provider
plugin plus `rtc-test-ws-sync-server.mjs` — only demonstrated
client-merging engines and none remains; the fixture files are kept
for reference but no suite uses them.) `.wp-env.json` maps
`tests/e2e/plugins` (that fixture) and
`gutenberg/packages/e2e-tests/plugins` (framework fixtures like
sync-connection-error-filter) as plugin dirs. `@y/websocket-server` is pinned
EXACTLY to 0.1.1 — 0.1.5 switched to the yjs-14 (`@y/y`) family and its daemon
crashes (`store.getClock is not a function`) when a 13.x client connects.
`npm run rtc:ws` is the one-command start for the REAL websocket transport
(manual two-window testing): it ensures the dev wp-env is running, activates
the right plugins, selects the websocket transport, and runs the
`wp collaboration sync-server` daemon in the wp-env cli container with port
8787 published to the host (wp-env alone cannot publish extra ports, and
the daemon must bind 0.0.0.0 — a loopback-bound daemon is unreachable even
through a published port). `npm run rtc:http` switches the site back to
HTTP polling and stops the daemon.

The DEV config's `afterStart` lifecycle hook runs the same script as
`--mode=daemon --detach || true`: every `npm run env start` brings the
daemon up automatically WITHOUT touching the site's transport selection
(`|| true` keeps a daemon failure from failing the start itself; the
diagnosis still prints in the spinner output). The daemon binds host port
8787 under a fixed container name, so with several checkouts/worktrees the
most recently started dev env owns it. The tests config has no hook — CI
and the test suites never start a daemon.

## Diagnostics

When something misbehaves, reach for these before adding printf debugging —
they exist so a failure is observable without re-instrumenting:

- **`npm run doctor`** — read-only environment preflight
  (`tests/e2e/bin/rtc-dev.mjs --mode=doctor`): builds present (plugin
  bundle, subtree, subtree node_modules), both wp-env environments
  (running? REST reachable? which port?), the worktree plugin-copy
  activation arrangement (double-mount fatals), whether the plugin
  actually loaded (`wp collaboration` commands registered), current
  engine/transport options, the foreign-wp-env-on-:8889 trap, and
  websocket daemon health. Exits non-zero on real problems, each with its
  fix. First stop when anything smells environmental — uniform timeouts
  across all engines are an environment failure, not an engine bug.
- **Browser wire inspector** — `window.wpSync` (`src/debug/inspector.ts`),
  on every editor page. `wpSync.enable()` (persists per profile), then
  `tail()` live-prints decoded traffic, `log()`/`table()` query the
  500-record ring buffer, `intents('p1')` filters history touching one
  syncId, `doc()`/`proposals()`/`cursor()` read live session state
  (intent-log), `export()` dumps JSON for bug reports, `help()` lists
  everything. Covers ALL transports: http-polling, http-long-polling, and
  websocket (sends and pushed receives are separate one-directional
  records on the socket lane).
- **Server `_debug` envelope** — enabling the inspector also stamps
  `debug: true` on each room request; all THREE engines respond with an
  `_debug` envelope (intent-log: lock wait, window rows, head seq, plan
  counts, checkpoint; yjs-server: doc bytes, appended rows, replay-repair
  flag, disposition counts; de-rtc: claim retries, version, content bytes,
  disposition counts, checkpoint) plus read-side row counts, printed as
  `⚙ server` in the tail. Gated server-side by `SCRIPT_DEBUG` (dev env:
  on; tests env: off) or the `wp_sync_debug_enabled` filter.
- **`qm/debug` narration** — all three engines and the polling transport
  narrate sync-critical events (lock timeouts, voided/escalated intents,
  repairs, checkpoints, trims, engine mismatches) through Query Monitor's
  `qm/debug` action; install Query Monitor on the dev site to see them.
- **`wp collaboration rooms`** — read-only server-side state dump:
  `wp collaboration rooms list` (every room: resolved name, engine
  lineage, row count, cursor) and `wp collaboration rooms inspect <room>
  [--rows=N] [--materialize] [--format=json]` (row-type histogram,
  decoded room meta — checkpoints, canonical doc sizes, floors —
  awareness, last-N decoded rows). Loaded ONLY under WP-CLI on
  local/development environments (wp-env reports `local`) or with the
  `GUTENBERG_SYNC_ENGINES_DIAGNOSTICS` constant — deliberately absent
  from the production path. It never creates storage posts (the storage
  API's own room lookup does — don't "just query storage" for diagnosis).
- **Session capture + request log** (`includes/diagnostics/`, same
  local/development-or-constant gate, but hooked on web requests too —
  no-ops until used): `wp collaboration capture start|stop|list|export|drop`
  records real `/wp-sync/` sessions and exports them in the community RTC
  performance harness's fixture format (replay/sanitize via
  `tests/debugging/replay/`); requests tagged `X-RTC-Test: 1` get
  per-request server metrics (dispatch/CPU ms, db_queries, db_time with
  SAVEQUERIES, memory, concurrency) logged with that harness's column
  conventions — read via `wp collaboration bench-log report [--all]` or
  the community-compatible `rtc-test/v1` REST routes
  (`/log`, `/report`, `/report-all`, `/env`). The transport benchmark
  tags its own traffic and folds these into its summary.
- **Fuzzer triage** — every run writes `summary.md` with normalized
  failure signatures and ready replay commands; `--shrink` bisects a
  reproducible failure to a minimal `--steps`; `RTC_FUZZ_LOG_SYNC=1`
  captures per-request wire summaries; every test attaches its full
  seeded action trace as `fuzz-run.json`. See `tests/fuzzer/README.md`.
- **`tests/tools/observe-two-tab-sync.mjs`** — manual two-tab observer
  against a live env: prints each tab's block store, canvas, and console
  errors for a scripted scenario.

## Gotchas (each of these has bitten — don't rediscover them)

- **Jest scope:** `jest.config.js` sets `roots: [src, tests]`. Without it,
  `wp-scripts test-unit-js` recurses into the subtree's ~1030 monorepo suites.
- **phpcs scope:** `phpcs.xml.dist` excludes `/gutenberg/*`.
- **wp-env is a devDep here.** `@wordpress/scripts` does NOT bundle it. It's
  pinned to `@wordpress/env@^11` (for auto-port) with a top-level `overrides`
  entry, because scripts@30 only *optionally* peer-depends on env 10 — the
  override clears the ERESOLVE without `--legacy-peer-deps`. Both configs
  set `testsEnvironment: false` — the tests site lives in its own config
  (`.wp-env.tests.json`), NOT in `.wp-env.json`'s deprecated combined mode.
  A dev-shaped env still mounts the phpunit library (`/wordpress-phpunit`)
  in its cli service, which is what lets `test:php` run there.
- **PHPUnit version:** composer pins `phpunit/phpunit:^9.6`. WordPress's test
  bootstrap calls `parseTestMethodAnnotations()`, removed in PHPUnit 10; letting
  `yoast/phpunit-polyfills` pull 10 makes every PHP test error.
- **PHP test bootstrap** (`tests/bootstrap.php`) loads the framework before the
  plugin: it resolves the framework plugin from `WP_SYNC_FRAMEWORK_PLUGIN`
  (env/const) else defaults to the subtree's wp-env path
  (`WP_PLUGIN_DIR/gutenberg/gutenberg.php`). Otherwise `WP_Sync_Post_Meta_Storage
  not found`.
- **e2e uses the subtree's collaboration fixtures**, so it must load a single
  `@playwright/test`. The subtree's `npm install` re-creates its own (identical)
  copy → Playwright "two instances" error. `pretest:e2e` rimrafs the subtree's
  copy so fixtures resolve up to this plugin's. The runner is `playwright test`
  **directly** — NOT `wp-scripts test-e2e` (v30's is the jest+puppeteer runner).
- **e2e global setup is plugin-local** (`tests/e2e/config/global-setup.ts`): auth,
  clean state, and — critically — activating `gutenberg-sync-engines` (by file
  path, worktree-safe), because wp-env leaves mapped plugins INACTIVE on the
  *tests* site. Without that, collaboration never turns on
  (`_wpCollaborationEnabled` stays false) and sessions time out. The framework
  itself needs no activation — the plugin loads its bundled Gutenberg — but the
  setup DOES deactivate a stale `gutenberg-stub` activation left by an aborted
  precedence-spec run (an active stub blocks the bundled framework). We deliberately
  do NOT reuse the subtree's global-setup (it deactivates a Gutenberg test
  plugin this env doesn't need touched). Ours also runs the WS-provider setup
  (`tests/e2e/config/rtc-websocket-setup.ts`), gated on
  `GUTENBERG_RTC_TEST_WS_PROVIDER`.
- **Collaboration gate:** `wp_is_collaboration_enabled()`, which since
  WordPress/gutenberg#80658 is just the Gutenberg experiment
  `gutenberg-real-time-collaboration`. The old `wp_collaboration_enabled`
  option and the Settings → Writing checkbox are GONE (Gutenberg deletes the
  option on upgrade), and the client flag is
  `window.__experimentalEnableRealTimeCollaboration`, not
  `window._wpCollaborationEnabled`. Tests flip the experiment through
  `gutenberg-experiments` in `POST /wp/v2/settings` (the fixture's
  `setCollaboration`) and set `wp_sync_engine` the same way; the CLI tools
  (`rtc-dev.mjs`, the fuzzer) flip it with a `wp eval` on that option. All of
  it only works with the plugins active. ACTIVATING this plugin turns the
  experiment on (`gutenberg_sync_engines_activate`, the entry file's
  activation hook, per site on a network-wide activation); it does not pin
  it, so turning the experiment off afterward still works — the e2e
  fixture's `setCollaboration( false )` teardown and the host benchmark's
  restore depend on that.
- **Subtree build layout** (Gutenberg 23.x): built package JS lands at
  `gutenberg/build/scripts/<pkg>/`, not `gutenberg/build/<pkg>/`.
- **Engine switches vs room lineage:** rooms are stamped with the engine
  that first wrote them, and the transport 409s mismatches
  (`rest_sync_engine_mismatch`). Global collection/taxonomy rooms (e.g.
  `taxonomy/wp_pattern_category`) outlive any engine flip, so the polling
  transport RESETS those rooms (rows + lineage + room meta) when a client
  speaking the newly-selected engine arrives — they're rebuildable
  change-feeds. Per-post entity rooms keep the strict fence (they can hold
  unsaved collaborative content; sessions degrade to the post lock).
  Related trap: the postmeta storage's `get_cursor()`/`get_update_count()`
  are per-request caches refreshed ONLY by `get_updates_after_cursor()` —
  never gate genesis (or anything) on them before a read has run.
- **A push dispatched from inside `SyncManager.update()` never reaches the
  editor.** core-data's `editEntityRecord` hands the sync manager the edits
  BEFORE it commits them, and every editor edit carries the editor's own
  block tree (`updateFootnotesFromMeta` always returns `{ blocks }`) — so
  the commit lands on top of any `editRecord` dispatched during the call.
  This is deterministic, not a race: capture-driven pushes must be
  dispatched from a later task (intent-log defers them past the typing
  burst; see `scheduleEditorSync`). Pushes made from a transport callback
  (a poll response) land normally. Rediscovering this costs an afternoon —
  the symptom is an editor whose tree silently never gets its syncIds.
- **Worktrees mount the plugin twice in wp-env:** `.wp-env.json` maps `.` to
  `wp-content/plugins/gutenberg-sync-engines` AND lists `.` in `plugins`,
  which also mounts it under the checkout's directory name. In the canonical
  checkout both paths coincide; in a worktree they don't, and activating both
  copies is a fatal `Cannot redeclare gutenberg_sync_engines_bootstrap()`.
  Keep the DIRECTORY-NAME copy active and the `gutenberg-sync-engines` copy
  inactive: `wp-env start` re-activates the plugins-list (directory-name)
  copy on EVERY start, so the reverse arrangement fatals — and aborts the
  start — the next time the env starts. `npm run rtc:ws` enforces the
  surviving arrangement automatically.

## Coding standards

- PHP: `composer lint` / `composer format` (PHPCS: WordPress-Core/Extra/Docs +
  PHPCompatibilityWP). JS/TS: `npm run lint:js` (lints `src` and `tests`,
  including the e2e specs) + `npm run format`
  (`@wordpress/prettier-config`).
- The frozen `src/engines/intent-log/**` core is excluded from prettier
  (eslint still runs it, with relaxed rules, and `tsc` type-checks it via
  `checkJs` + JSDoc); the vendored `src/engines/yjs/y-utilities/**` is
  excluded from both — leave them alone unless deliberately syncing the
  cross-language contract (JSDoc-only edits to the core are fine).

## Commits / PRs

- **`CHANGELOG.md` records significant changes only.** Add an entry under
  **Unreleased**, as part of the change itself (same commit or PR), when a
  change is one of these: a new feature, a new setting or extension point,
  behavior that is removed or works differently, or anything a site owner
  or developer must know about when they upgrade. Do NOT add entries for
  bug fixes, tests, benchmarks, the fuzzer or other developer tooling,
  refactors, or docs — the commit message and the issue record those. Why:
  nearly every branch used to touch the same lines of the changelog, so
  merges conflicted constantly. Keep each entry to one or two sentences
  and link the issue. When in doubt, leave it out: when a version ships,
  the release script appends every commit merged since the last release
  under that version, each linked to its pull request, so nothing is
  lost by leaving an entry out (`npm run release -- --dry-run` previews
  that list).
- This repo has commit **signing disabled locally**. Commit with `--no-verify`
  (the pre-commit hook is heavy/flaky).
- Do **not** open PRs / push to shared branches / take other outward-facing
  actions unless the user names that specific action.

## Releasing (HUMANS ONLY)

Releasing is a **human-only** action. Agents must never run `npm run
release`, trigger either release workflow, push a `release/*` branch, tag a
version, or bump the plugin version — not even when a release "seems ready".
An agent's entire involvement in releasing is: keep the changelog's
Unreleased section accurate (significant changes only, see Commits / PRs)
and use `@since n.e.x.t` in new code (the release tooling stamps the real
version).

## Known issues / out of scope

Open work lives in **GitHub Issues** (`gh issue list --label "agent:ready"`).
Anyone can file one; an agent investigates it and rewrites it into the
shape defined by `.github/ISSUE_TEMPLATE/shaped-issue.md`. Read
`docs/plan/README.md` for the rules and the label set before touching any of
it. **Write plainly.** The rule is mechanical: if a word is defined in
`docs/glossary.md`, it is one of our invented words and does not belong
in an issue's title, problem, or example — only in its notes.

Ideas we looked at and set aside are in `docs/plan/wontfix.md`.
`docs/plan/history.md` records why the code is shaped the way it is and what
has already been tried and failed — read it before a big change, and
before re-attempting anything that looks obvious.

`LOOP.md` is the working ledger when the issue loop is running
(`/loop /shape-issue` to work up what was filed, then `/loop /solve-issue`;
either also takes a single issue number directly).

This section carries the operational facts and cites issues where one
applies.

- `composer lint` is clean (zero errors, zero warnings) — keep it
  that way; the
  excludes (`gutenberg/`, frozen cores, vendored libraries) are by
  design and must not widen.
- All three engines have **collaborative undo**: intent-log via inverse
  intents over the accepted log (`src/engines/intent-log-undo.ts` — a
  still-pending unit CANCELS with an outbox removal plus a wire-chasing
  `cancel` row, a settled unit inverts; inverses derive only from
  ACCEPTED rows), yjs-server via the shared `src/engines/yjs/undo.ts`,
  and de-rtc via revert-edit undo (reverts derived from the client's
  own accepted canonical rows, proposed as ordinary new changes).
- **Conflict review is cross-engine**: intent-log through its bespoke
  manager; de-rtc parks escalations as durable `parked` rows and
  presents them through the framework review panel via
  `src/engines/review-manager-decorator.ts` (the plumbing any
  createSyncManager-composed engine can reuse); yjs-server has NO review
  lane by design (CRDT merge detects no conflicts to park).
- **Shared genesis property seed**: all three engines seed
  `WP_Sync_Post_Genesis_Props::for_post()` (REST-shaped scalars,
  taxonomies by rest_base, `meta.<key>`), so joiners see identical field
  state under any engine and never open dirty.
- **yjs-server known gaps** (docs/engine-comparison.md has the full list):
  ingest cost is real y-php CPU — the canonical doc is
  decoded/merged/re-encoded per request, the most expensive per-ingest
  path of the three engines (run `npm run bench -- --suite=engines` for
  numbers), no
  review lane
  (register conflicts LWW silently), kses is sanitize-and-compensate (no
  human review of stripped markup), rooms are size-gated at both ends
  (genesis refuses above `wp_sync_yjs_server_max_genesis_bytes`, 1 MB
  default; a room grown past `wp_sync_yjs_server_max_room_bytes`, 8 MB
  default, rejects further writes with 413 while reads/saves continue —
  shrinking an over-limit room via epoch compaction stays post-v1).
  Materialization fidelity is FIXED as of PR #35: every Y.Block carries
  a `_save` mirror (its registered save() output, refreshed on attribute
  merges; the subtree's `crdt-blocks.ts` writes it under the exported
  `CRDT_BLOCK_SAVE_KEY`) and the engine prefers it over genesis
  wrappers, so attribute-driven wrapper changes materialize. The genesis
  rich-text defect (stripped inner markup landing in the first
  rich-text-source attribute) was fixed separately by the selector-
  sourced split. Genesis blocks must still set `isValid: true` or the
  editor renders them as invalid-content recovery blocks (has bitten) —
  and a container-shaped variant of exactly that symptom is open, see
  issue #38.
- **de-rtc known gaps** (docs/engine-comparison.md has the full list):
  every block carries a durable `metadata.syncId` (intent-log's scheme;
  `WP_De_RTC_Block_Identity` stamps genesis deterministically and
  engine-unaware writers' blocks, `adopt()` lines an id-less copy up
  with its base by path, and the editor-side stamper in
  `includes/engines/intent-log/sync-id.js` serves de-rtc too) and
  `WP_De_RTC_Identity_Merge` three-way merges by that identity at every
  depth BEFORE the frozen positional core (which stays the fallback
  whenever identity declines: id-less blocks, classic content between
  blocks, irregular containers); parked rows carry `syncId` + `path`
  beside `index`, the client restores/contests/anchors by syncId
  (`DeRtcContestKey`), `blockBaseVersions` keys may be syncIds, kses
  sequestration (`WP_De_RTC_Identity_Merge::sequester`), authorship
  (`getBlockAuthorshipById`) and revert-undo all work by identity at
  every depth with the positional rules as the id-less fallback;
  truly concurrent SAME-block edits merge from their TRUE base
  (`blockBaseVersions`) or raise a contested pending item
  (Adopt/Reject) — the old silent client-side block LWW is
  retired; sessions author the block-native `clientUpdate` descriptor
  (tamper evidence, byte-parity with the PHP derivation pinned
  by PHP-generated vectors in
  `tests/js/engines/de-rtc/test-vectors/`; the engine validates once
  against the plain declared base, then drops it), while machine
  writers stay descriptor-less via the server's engine-unaware-writer
  lane; kses SEQUESTERS per block (risky blocks revert to base and
  park for review while the safe remainder lands; whole-proposal
  escalation remains the fallback for freeform boundaries); ingest is
  lock-free — each accepted
  proposal atomically claims its version advancement (options-row CAS,
  `WP_Sync_Atomic_Option`) and a lost claim reloads + re-merges, the
  upstream optimistic model. Since protocol 2 the
  transport carries ADVISORIES, not documents: accepted proposals
  broadcast ~200-byte `announce` rows (version + canonicalized content
  hash + merged property registers); canonical content lives once per
  room in a CHAINED options row (`swap_prefixed` — writers CAS against
  their predecessor's sequence prefix, so canonical persistence can
  never regress), and a behind client's `fetch` row is answered with
  one synthesized, never-stored snapshot. The active typist advances
  by hash and downloads nothing; row bytes no longer scale with
  document size (the hour soak's PHP-memory cliff, closed structurally;
  re-measured at hour scale: request rate flat, peak PHP memory 9 MB).
  Stage 2 completes the Save/Sync inversion: sessions COMMIT through
  the ordinary autosave endpoint (`WP_De_RTC_Autosave_Commits`
  intercepts the commit shape; editor-native autosaves pass through),
  the transport carries ZERO proposals, and editor saves settle-and-
  hold the commit lane (`prepareForSave`) so a save can never
  self-conflict with the session's own in-flight commit (fuzzer-found).
  Do NOT reintroduce a `content` entry into de-rtc's property lane —
  it silently re-carries the whole document per announce (found by
  wire inspection; stripped on both sides).
  A second commit hold matters just as much: while `pendingOwnMergeSeq`
  is set — the server merged peers' work into our proposal, so a newer
  version exists whose content we do not hold yet — `maybePropose`
  must NOT build a proposal. Its base would be the dead pre-merge
  version, and the server would three-way-merge our OWN just-accepted
  keystroke as a foreign concurrent change: both sides changed the
  block, so it parks and canonical wins. That silently ate the rest of
  every typing burst that straddled a commit round trip (" from two"
  collapsing to " "), and it only showed up on hosts slow enough to
  split a burst across commits — fast machines finish the burst before
  the first commit leaves. Regression-tested deterministically in
  `tests/js/engines/de-rtc/announce.test.ts`; the de-rtc e2e
  concurrency spec now types with a per-keystroke delay so the
  interleaving happens on every host, not just slow ones.
- **Intent-log observed-baseline residuals** (the echo race is FIXED — capture
  now diffs the editor tree against the document state that tree reflects and
  authors at its seq; see the "THE OBSERVED BASELINE" note in
  `src/engines/intent-log-manager.ts`). What remains:
  - Which state the editor last displayed is inferred, not observed: an
    arriving tree is matched to the nearest candidate (`documentDistance`)
    among the confirmed baseline and the unconfirmed pushes. Ties keep the
    confirmed baseline, so the failure direction is a re-pushed block rather
    than a destroyed edit.
  - Typing INTO a paragraph a peer is editing, while this editor is still
    behind on their change, escalates the later keystrokes of the burst
    (`frame-conflict`, engine rule 5) instead of merging them: their offsets
    sit in a frame both an earlier own edit and a remote edit wrote. They go
    to the review lane — parked, never lost — and normal merging resumes as
    soon as the editor observes the remote change. (The related
    one-keystroke DIVERGENCE this used to cause is FIXED: a settle that
    bypasses `clientReceive` — parked rows, voided markers, disposition
    acks — now replans the optimistic document, so a mispredicted escalated
    keystroke can no longer linger on the author's canvas forever; found by
    the fuzzer's concurrency profile, regression-tested in
    `tests/js/engines/intent-log-manager.test.ts`.)
  - Capture-driven pushes wait for the typing burst to fall quiet
    (`CAPTURE_SYNC_DELAY`, 1.2 s), so identity write-backs and merged views
    reach the canvas that late. This is forced by core-data (see the gotcha
    on pushes from inside `update()`), not by choice.
  - An undo whose inverse intents are still unacked when that tab reloads
    loses them with the outbox: the undone edit (already accepted
    server-side) resurrects for everyone. The general unacked-edit-loss
    window, but undo makes it visible (the user watched the text vanish).
  - FIXED: an edit made DURING the join
    round trip used to stay local forever on an empty-genesis room
    (found 2026-08-17 as a reload straddling a block insert — update()
    dropped pre-init trees and the empty-genesis bootstrap pushes
    nothing that would reconcile). update() now buffers the latest
    pre-init tree and an empty-genesis bootstrap captures it via a
    DEFERRED recovery that runs only if the document is still empty
    after the delivery burst — a rejoiner's history replays right
    behind the genesis row, and capturing against the bare genesis
    baseline would duplicate every saved block (fuzz:quick caught the
    synchronous variant). Regression tests in
    `tests/js/engines/intent-log-manager.test.ts`; the old replay
    (`npm run fuzz -- --combos=intent-log/http-polling --seed-list=6
    --steps=14 --profile=concurrency`) passes. Pre-init edits on
    NON-empty bootstraps are still discarded (reconciled by the
    bootstrap push, which clobbers them) — pre-existing behavior,
    unchanged.

## Deep history

The backstory of the multi-month RTC effort (framework/plugin split, engine
SPI, transports, benchmarks, the subtree/e2e work) lives in this repo:
`docs/plan/history.md` records where the project came from, the decisions
that shape the code today, and what has already been tried and failed;
`docs/architecture-decisions.md` records the load-bearing early decisions
still open to revisiting. Read both before a big change.
