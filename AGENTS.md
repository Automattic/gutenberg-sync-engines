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
  by transform, sets genuine conflicts aside for review) and `yjs-server`
  (server-authoritative CRDT: the vendored y-php library merges every update
  into a canonical room document server-side, compacts by itself, and
  materializes post content — lock-free ingest; it inherited the retired
  naive-relay yjs-relay engine's client CRDT machinery and wire format).
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
- `includes/` — server PHP: `engines/{intent-log,yjs-server}/`,
  `transports/{...,websocket/}`, `admin/` (the Collaboration settings screen),
  and `lib/`:
  - `lib/y-php/` — **vendored y-php** (PHP port of Yjs 13.6.31), imported
    verbatim from <https://github.com/alecgeatches/y-php> (MIT; upstream
    commit recorded in the import commit). Excluded from our phpcs (it
    deliberately mirrors JS Yjs style and carries its own configs). Its own
    conformance suite runs in CI:
    `composer --working-dir=includes/lib/y-php install && composer
    --working-dir=includes/lib/y-php test` (~4 s, no WordPress needed).
    Treat it like the frozen intent-log core: don't casually edit — its
    contract is byte-parity with JS Yjs, enforced by translated upstream
    tests + fixtures generated from the real JS implementation.
  - `lib/yjs/tests/compatibility.tests.js` — single fixture file from JS
    Yjs v13.6.31 (MIT), vendored at the sibling path y-php's
    CompatibilityTest expects.
  - `lib/y-php-loader.php` — lazy runtime loader (PSR-4 autoloader +
    Composer-`files` equivalents) so the plugin can use y-php without a
    Composer autoloader.
- `src/` — client JS/TS (webpack entry `src/index.ts` → `build/sync-engines.js`,
  externalizes `@wordpress/sync`→`wp.sync` and `yjs`→`wp.sync.Y`):
  - `engines/intent-log/` — the **frozen cross-language core** (byte-matched
    against its PHP twin + JSON vectors). Excluded from lint/format. `genesisSyncId`
    lives at `src/engines/intent-log/sync-id.js`. Don't casually edit — changes
    must stay in lockstep with the PHP core and test vectors. Its Jest harness
    lives in `tests/js/engines/intent-log/`; its vector generators in
    `tests/tools/`.
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
  and
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

```bash
npm run env start         # wp-env: Gutenberg subtree + this plugin
npm run env stop
```

Dev site <http://localhost:8888>, tests site <http://localhost:8889>.
`autoPort` is on, so when those are busy wp-env picks free ports and prints the
URLs it chose. Force ports with `WP_ENV_PORT` / `WP_ENV_TESTS_PORT`.

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

`test:php` and `test:e2e` need a running env (`npm run env start`) with the
subtree built. For e2e also run `npx playwright install chromium` once. If the
tests site isn't on `:8889` (auto-port / override), pass
`WP_BASE_URL=http://localhost:<tests-port>`. Beware: if ANOTHER project's
wp-env holds `:8889`, Playwright's webServer check sees the port alive and
silently reuses that foreign site (wp-env credentials are identical
everywhere, so auth even succeeds); the first visible failure is
`activatePlugin( 'gutenberg' )` in global-setup dying with
"Unexpected end of JSON input". Always pass `WP_BASE_URL` in that case.

Current green baseline: **Jest 339**, **PHPUnit 148 (659 assertions)**,
**e2e 29/29** (occasional flake under full-suite load — a save notice or a
fixture login navigation; green solo), **e2e:websocket 1 skipped** (see
below — the peer-relay WS fixture needs a client-merging engine and none
remains), plus the
vendored y-php library's own conformance suite (**428 tests**, run
separately — see `includes/lib/` above). CI (`.github/workflows/ci.yml`)
certifies all suites on pushes to `main` and PRs; the e2e job leans on the
base config's 2-retries-in-CI to absorb the flake.

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
(manual two-window testing): it ensures wp-env is running, activates the
right plugins, selects the websocket transport, and runs the
`wp collaboration sync-server` daemon in the wp-env cli container with port
8787 published to the host (wp-env alone cannot publish extra ports, and
the daemon must bind 0.0.0.0 — a loopback-bound daemon is unreachable even
through a published port). `npm run rtc:http` switches the site back to
HTTP polling and stops the daemon. `--detach` starts the daemon in the
background and exits; to auto-start it with every `wp-env start`, put this
in the gitignored `.wp-env.override.json`:

```json
{
	"lifecycleScripts": {
		"afterStart": "node tests/e2e/bin/rtc-dev.mjs --mode=websockets --detach || true"
	}
}
```

(The `|| true` keeps a daemon failure from failing `wp-env start` itself;
the diagnosis still prints in the spinner output.)

## Gotchas (each of these has bitten — don't rediscover them)

- **Jest scope:** `jest.config.js` sets `roots: [src, tests]`. Without it,
  `wp-scripts test-unit-js` recurses into the subtree's ~1030 monorepo suites.
- **phpcs scope:** `phpcs.xml.dist` excludes `/gutenberg/*`.
- **wp-env is a devDep here.** `@wordpress/scripts` does NOT bundle it. It's
  pinned to `@wordpress/env@^11` (for auto-port) with a top-level `overrides`
  entry, because scripts@30 only *optionally* peer-depends on env 10 — the
  override clears the ERESOLVE without `--legacy-peer-deps`. env 11 prints a
  harmless tests-environment deprecation warning; do NOT add
  `testsEnvironment: false` (it disables the tests site `test:php`/`test:e2e`
  need).
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
- **Intent-log echo race:** editor pushes racing live keystrokes can corrupt
  canvas text (observed under load; worst over websocket's per-keystroke
  cadence — benchmark that transport under yjs-server meanwhile). Deferring or
  gating pushes is NOT a fix: capture treats the editor tree as full testimony
  against the current document, so a deferred push makes the next capture
  author intents reverting the un-pushed remote content. The designed fix is
  capturing against the editor's last-observed document state (author at that
  base seq; the engine transform merges) — a session/bridge redesign. See the
  KNOWN LIMITATION comment at the delayed re-push in
  `src/engines/intent-log-manager.ts`.

## Deep history

The multi-month RTC effort (framework/plugin split, engine SPI, transports,
benchmarks, the subtree/e2e work) is recorded in the **Gutenberg project's**
agent memory at
`/Users/zzz/.claude/projects/-Users-zzz-Code-gutenberg/memory/` — start at
`MEMORY.md`, especially the `rtc-plugin-split` and `try-intent-log-engine`
entries. A fresh session in *this* repo does not load that memory automatically;
read it directly when you need the backstory.
