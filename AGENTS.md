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
  by transform, sets genuine conflicts aside for review) and `yjs-relay` (naive
  relay of opaque Yjs CRDT updates, the incumbent).
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
- `includes/` — server PHP: `engines/{intent-log,yjs-relay}/`,
  `transports/{...,websocket/}`, `admin/` (the Collaboration settings screen).
- `src/` — client JS/TS (webpack entry `src/index.ts` → `build/sync-engines.js`,
  externalizes `@wordpress/sync`→`wp.sync` and `yjs`→`wp.sync.Y`):
  - `engines/intent-log/` — the **frozen cross-language core** (byte-matched
    against its PHP twin + JSON vectors). Excluded from lint/format. `genesisSyncId`
    lives at `src/engines/intent-log/sync-id.js`. Don't casually edit — changes
    must stay in lockstep with the PHP core and test vectors. Its Jest harness
    lives in `tests/js/engines/intent-log/`; its vector generators stay here in
    `tools/`.
  - `engines/yjs-relay/` — the Yjs engine + its `undo.ts` + vendored
    `y-utilities/` (ignored by eslint).
  - `providers/{http-polling,http-long-polling,websocket}/` — transports.
  - `framework.ts` — unlocks `@wordpress/sync` private APIs once and re-exports
    the framework runtime the adapters use.
- `gutenberg/` — a **pinned, squashed git subtree of Gutenberg** (source only;
  see below). Mounted by `.wp-env.json` as the runtime framework.
- `tests/` — ALL tests and fixtures: `tests/phpunit/` (PHPUnit, boots via
  `tests/bootstrap.php`), `tests/js/` (Jest unit tests + setup files, mirroring
  `src/`; `tests/js/engines/intent-log/` is the frozen core's harness),
  `tests/e2e/` (Playwright specs + config; see Testing). The frozen intent-log
  vectors exist as TWO deliberate copies — `tests/js/engines/intent-log/
  test-vectors/` (replayed by Jest) and `tests/phpunit/test-vectors/` (replayed
  by PHPUnit) — kept byte-identical by `tests/js/engines/intent-log/
  vector-parity.test.js`; regenerate with the `src/engines/intent-log/tools/`
  scripts and always update both.
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
npm run test:js           # Jest: engines/providers + frozen-core vectors
npm run test:php          # PHPUnit in the wp-env tests container
npm run test:e2e          # Playwright: two-browser collaboration
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

Current green baseline: **Jest 373**, **PHPUnit 133 (562 assertions)**,
**e2e 20/20** (occasional save-notice flake under full-suite load; green solo).

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
  do NOT reuse the subtree's global-setup (it deactivates a Gutenberg test plugin
  this env doesn't map and provisions the RTC websocket daemon).
- **Collaboration gate:** `wp_is_collaboration_allowed() &&
  get_option('wp_collaboration_enabled')`. Tests flip that option via the
  writing-options form (the fixture's `setCollaboration`) and set `wp_sync_engine`
  via `POST /wp/v2/settings` — both only work with the plugins active.
- **Subtree build layout** (Gutenberg 23.x): built package JS lands at
  `gutenberg/build/scripts/<pkg>/`, not `gutenberg/build/<pkg>/`.

## Coding standards

- PHP: `composer lint` / `composer format` (PHPCS: WordPress-Core/Extra/Docs +
  PHPCompatibilityWP). JS/TS: `npm run lint:js` (lints `src` and `tests`,
  including the e2e specs) + `npm run format`
  (`@wordpress/prettier-config`).
- The frozen `src/engines/intent-log/**` core and vendored
  `src/engines/yjs-relay/y-utilities/**` are excluded from lint/format — leave
  them alone unless deliberately syncing the cross-language contract.

## Commits / PRs

- This repo has commit **signing disabled locally**. Commit with `--no-verify`
  (the pre-commit hook is heavy/flaky).
- Do **not** open PRs / push to shared branches / take other outward-facing
  actions unless the user names that specific action.

## Known issues / out of scope

- `composer lint` currently reports ~275 errors + ~29 warnings in the plugin's
  own `includes/`, `tests/`, `tools/` PHP — pre-existing standards debt, not yet
  addressed. `composer format` auto-fixes a handful.
- Intent-log has **no collaborative undo** yet (leaves the manager undefined; WP
  global undo applies). The designed fix is inverse-intent undo. yjs-relay
  provides undo via `src/engines/yjs-relay/undo.ts`.

## Deep history

The multi-month RTC effort (framework/plugin split, engine SPI, transports,
benchmarks, the subtree/e2e work) is recorded in the **Gutenberg project's**
agent memory at
`/Users/zzz/.claude/projects/-Users-zzz-Code-gutenberg/memory/` — start at
`MEMORY.md`, especially the `rtc-plugin-split` and `try-intent-log-engine`
entries. A fresh session in *this* repo does not load that memory automatically;
read it directly when you need the backstory.
