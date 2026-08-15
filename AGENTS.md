# AGENTS.md

Operational guide for working in this repo. Read this first.

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
  the wordpress-develop `add/distributed-editing` branch and broadcasts
  canonical content rows; genuine conflicts escalate instead of silently
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
    commit recorded in the import commit). ONE deliberate local delta:
    `composer.json` pins `config.platform.php` to 7.4 (with the lock
    resolved for it) so the suite installs on WP-supported PHP — preserve
    it when re-vendoring. Excluded from our phpcs (it
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
    upstream submodule that was never committed). Running the suite
    rewrites the tracked `PORTING_STATUS.json` (timestamps); revert that
    side-effect after local runs. NOTE: the DE-RTC
    *shipping* merge path (`native-automerge-blocks-v1`) never calls this
    library — it backs only the dead legacy whole-text lane and
    external-repair; it is vendored for fidelity and future use.
  - `lib/automerge-php-loader.php` — lazy PSR-4 loader shim +
    `gutenberg_sync_engines_automerge_php_is_supported()` (PHP ≥ 8.2 +
    mbstring gate).
- `src/` — client JS/TS (webpack entry `src/index.ts` → `build/sync-engines.js`,
  externalizes `@wordpress/sync`→`wp.sync` and `yjs`→`wp.sync.Y`):
  - `engines/intent-log/` — the **frozen cross-language core** (byte-matched
    against its PHP twin + JSON vectors). Excluded from lint/format. `genesisSyncId`
    lives at `src/engines/intent-log/sync-id.js`. Don't casually edit — changes
    must stay in lockstep with the PHP core and test vectors. Its Jest harness
    lives in `tests/js/engines/intent-log/`; its vector generators in
    `tests/tools/`. One file is client-only: `client.js` (the replica —
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
  see below). Mounted by `.wp-env.json` as the runtime framework.
- `tests/` — ALL tests, fixtures, and test tooling: `tests/phpunit/` (PHPUnit,
  boots via `tests/bootstrap.php`), `tests/js/` (Jest unit tests + setup files,
  mirroring `src/`; `tests/js/engines/intent-log/` is the frozen core's
  harness), `tests/e2e/` (Playwright specs + config; `specs/http-only/` and
  `specs/websocket-only/` are the transport-specific suites relocated from the
  framework, `plugins/` holds the test WebSocket provider fixture plugin,
  `bin/` the y-websocket sync-server daemon + the `rtc:ws`/`rtc:http` dev
  switcher for the real websocket transport; see Testing),
  `tests/benchmarks/` (the sync-engine benchmark harness, run via `wp
  eval-file tests/benchmarks/benchmark.php`, plus the browser-driven
  transport benchmark in `tests/benchmarks/transport/`; see their READMEs),
  `tests/fuzzer/` (the seeded browser fuzzer swept across every
  engine × transport combo — `npm run fuzz`; see its README for strategy,
  replay, and triage), and
  `tests/tools/` (Node CLI scripts: vector generators, the simulator sweep,
  the manual two-tab observer). The frozen intent-log vectors exist as TWO
  deliberate copies — `tests/js/engines/intent-log/test-vectors/` (replayed by
  Jest) and `tests/phpunit/test-vectors/` (replayed by PHPUnit) — kept
  byte-identical by `tests/js/engines/intent-log/vector-parity.test.js`;
  regenerate with the `tests/tools/` scripts and always update both.
- `docs/engine-comparison.md` — the engine comparison guide: parity table,
  host resource profiles, measured transport numbers, known gaps. The
  interpretation layer over both benchmark harnesses; keep it current when
  engine capabilities or benchmarks change.
- `PORTING.md` — historical record of the client-side split (mostly DONE).

## The `gutenberg/` subtree

The plugin needs the exact Gutenberg framework it was built against, so a
Gutenberg checkout is vendored as a **squashed git subtree** pinned to a
specific framework commit, mounted via `.wp-env.json` (`plugins: ["./gutenberg",
"."]`). It is committed **source-only** — its `node_modules/` and `build/` are
gitignored (by Gutenberg's own nested `.gitignore`) and must be generated
locally (see Setup).

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
npm run env start         # DEV env (.wp-env.json): Gutenberg subtree + this
                          # plugin, http://localhost:8888. Its afterStart
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
everywhere, so auth even succeeds); the first visible failure is
`activatePlugin( 'gutenberg' )` in global-setup dying with
"Unexpected end of JSON input". Always pass `WP_BASE_URL` in that case.

Current green baseline: **Jest 368**, **PHPUnit 197 (898 assertions)**,
**e2e 45/45** (occasional flake under full-suite load — a save notice, a
fixture login navigation, or `http-only/collaboration-sync-body-size`
failing after a preceding engine-flip suite [verified pre-existing: the
yjs suite followed by body-size reproduces it without de-rtc involved];
each green solo), **e2e:websocket 1 skipped** (see
below — the peer-relay WS fixture needs a client-merging engine and none
remains), plus the vendored libraries' own conformance suites run
separately: y-php (**442 tests**) and automerge-php (**680 mapped
upstream tests**, `php includes/lib/automerge-php/tests/run.php`). CI
(`.github/workflows/ci.yml`) certifies all suites on pushes to `main` and
PRs; the e2e job leans on the base config's 2-retries-in-CI to absorb the
flakes.

The transport-specific e2e suites live here (relocated from the framework):
`tests/e2e/specs/http-only/` runs in the default suite; `tests/e2e/specs/
websocket-only/` runs only under `test:e2e:websocket`
(`playwright.rtc-websocket.config.ts` sets `GUTENBERG_RTC_TEST_WS_PROVIDER=1`,
starts `tests/e2e/bin/rtc-test-ws-sync-server.mjs` as a second webServer, and
global-setup builds + activates the test WS provider plugin from
`tests/e2e/plugins/rtc-websocket-provider/`; the default suite deactivates it).
The fixture plugin implements the session-codec provider contract by
relaying opaque `EngineUpdate` envelopes through a per-room Y.Doc over
y-websocket — a pure PEER relay with no WP server in the loop, which only
demonstrates collaboration under a client-merging engine. Since the
yjs-relay engine was removed, its one spec is `test.fixme`-skipped: both
remaining engines are server-authoritative (yjs-server clients wait for the
server's genesis snapshot, so nothing syncs over a serverless relay).
Re-enable by pointing the suite at the plugin's real websocket transport
(the `wp collaboration sync-server` PHP daemon) or giving the fixture a
server lane. `.wp-env.json` maps `tests/e2e/plugins` (this fixture) and
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
  clean state, and — critically — `activatePlugin('gutenberg')` +
  `activatePlugin('gutenberg-sync-engines')`, because wp-env leaves both INACTIVE
  on the *tests* site. Without that, collaboration never turns on
  (`_wpCollaborationEnabled` stays false) and sessions time out. We deliberately
  do NOT reuse the subtree's global-setup (it deactivates a Gutenberg test
  plugin this env doesn't need touched). Ours also runs the WS-provider setup
  (`tests/e2e/config/rtc-websocket-setup.ts`), gated on
  `GUTENBERG_RTC_TEST_WS_PROVIDER`.
- **Collaboration gate:** `wp_is_collaboration_allowed() &&
  get_option('wp_collaboration_enabled')`. Tests flip that option via the
  writing-options form (the fixture's `setCollaboration`) and set `wp_sync_engine`
  via `POST /wp/v2/settings` — both only work with the plugins active.
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
- The frozen `src/engines/intent-log/**` core and vendored
  `src/engines/yjs/y-utilities/**` are excluded from lint/format — leave
  them alone unless deliberately syncing the cross-language contract.

## Commits / PRs

- This repo has commit **signing disabled locally**. Commit with `--no-verify`
  (the pre-commit hook is heavy/flaky).
- Do **not** open PRs / push to shared branches / take other outward-facing
  actions unless the user names that specific action.

## Known issues / out of scope

- `composer lint` currently reports ~275 errors + ~29 warnings in the plugin's
  own `includes/` and `tests/` PHP — pre-existing standards debt, not yet
  addressed. `composer format` auto-fixes a handful.
- Intent-log has **no collaborative undo** yet (leaves the manager undefined; WP
  global undo applies). The designed fix is inverse-intent undo. yjs-server
  provides undo via the shared `src/engines/yjs/undo.ts`.
- **yjs-server known gaps** (docs/engine-comparison.md has the full list):
  ingest cost is real y-php CPU (~30 ms/edit at benchmark sizes — the
  canonical doc is decoded/merged/re-encoded per request), no kses/capability
  lane yet, no review lane (register conflicts LWW silently), no
  document-size gate for later joiners (the retired relay's client-side
  genesis tripped the framework size guard for every visitor; with server
  genesis only the oversized author's own tab is fenced), and
  materialization carries the same Phase-2a wrapper simplification as
  intent-log. Genesis blocks must set `isValid: true` or the editor renders
  them as invalid-content recovery blocks (has bitten).
- **de-rtc known gaps** (docs/engine-comparison.md has the full list): no
  title sync (proposals carry content only); no review lane yet — server
  escalations (`manual-conflict-required`, `requires-unfiltered-html`)
  surface as dispositions but no UI presents them, and the client abandons
  an escalated proposal once canonical applies; truly concurrent SAME-block
  edits resolve block-level last-writer-wins client-side (yjs-server's
  silent-register-LWW class, coarser grain); the client sends
  `clientUpdate: null` and relies on the server's engine-unaware-writer
  lane to derive block-native operations (the client-side descriptor
  builder + cross-language fingerprint vectors are unported); no benchmark
  authoring profile yet (`tests/benchmarks` cannot drive de-rtc); ingest
  serializes per room under the intent-log-style GET_LOCK, and every
  accepted proposal broadcasts FULL content rows (storage bounded by
  checkpoints, but row bytes scale with document size).
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
    soon as the editor observes the remote change.
  - Capture-driven pushes wait for the typing burst to fall quiet
    (`CAPTURE_SYNC_DELAY`, 1.2 s), so identity write-backs and merged views
    reach the canvas that late. This is forced by core-data (see the gotcha
    on pushes from inside `update()`), not by choice.

## Deep history

The multi-month RTC effort (framework/plugin split, engine SPI, transports,
benchmarks, the subtree/e2e work) is recorded in the **Gutenberg project's**
agent memory at
`/Users/zzz/.claude/projects/-Users-zzz-Code-gutenberg/memory/` — start at
`MEMORY.md`, especially the `rtc-plugin-split` and `try-intent-log-engine`
entries. A fresh session in *this* repo does not load that memory automatically;
read it directly when you need the backstory.
