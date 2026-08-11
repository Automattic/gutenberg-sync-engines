# Gutenberg Sync Engines

Pluggable real-time collaboration **engines** and **transports** for the
Gutenberg collaborative-editing framework.

Gutenberg (WordPress core) hosts the collaboration *framework*: the
`WP_Sync_Engine` / `WP_Sync_Transport` / `WP_Sync_Storage` contracts, the two
registries, room permission config, storage, the client `@wordpress/sync`
package, and the editor/data-layer integration (including the conflict-review
UI). This plugin supplies the *implementations* that register through the
framework's extension filters.

**Without this plugin active, real-time collaboration is effectively
disabled** — the framework registers no engine or transport, so a session
finds nothing to negotiate and the editor falls back to the classic
exclusive post lock.

## What it provides

Engines (how concurrent edits merge):

- **intent-log** — a server-authoritative log of typed intents; concurrent
  edits merge by transform, genuine conflicts are set aside for review, and
  no work is silently lost.
- **yjs-relay** — a naive relay of opaque Yjs CRDT updates (the incumbent).

Transports (how updates move):

- **http-polling** — short-poll `POST /wp-sync/v1/updates` (default).
- **http-long-polling** — the same, held open until data is ready.
- **websocket** — push over a persistent socket served by a bundled PHP
  daemon (`wp collaboration sync-server`).

The active engine and transport are chosen on the plugin's **Settings →
Collaboration** screen (or via `wp_sync_engine` / the
`WP_COLLABORATION_TRANSPORT` config value).

## Architecture

Both axes are independent registries with a client/server handshake: the
server announces the active engine + transport, the client negotiates
against what it has registered, and any mismatch degrades to a post lock
rather than corruption. See Gutenberg's
`prototypes/sync/ARCHITECTURE.md` for the full picture.

The plugin registers via:

- PHP: the `wp_sync_engines` and `wp_sync_transports` filters.
- JS: `registerSyncEngine` / `registerSyncTransport`, unlocked from
  `@wordpress/sync`'s private APIs.

## Development

This plugin requires the Gutenberg collaborative-editing framework at runtime.
That framework is vendored as a **pinned git subtree** in `gutenberg/` (mounted
by `.wp-env.json`), so the local WordPress environment runs the exact Gutenberg
the engines were built against — no separate checkout needed.

### Setup

```bash
composer install          # PHP tooling (PHPCS/WPCS, PHPUnit + polyfills)
npm install               # JS tooling (@wordpress/scripts, wp-env, Playwright)
npm run build             # Build this plugin's client bundle

# Build the vendored Gutenberg once, so wp-env serves working editor assets.
# (The subtree is committed as source only; its build output is not.)
# Jest and `npm run typecheck` also resolve @wordpress/sync + yjs from this
# build. `--ignore-scripts` is required: Gutenberg's `prepare` hook (husky)
# errors inside a subtree, and the lifecycle outputs it skips are regenerated
# by `npm run build`.
cd gutenberg && npm install --ignore-scripts && npm run build && cd ..
```

### Environment

```bash
npm run env start         # Start WordPress (Gutenberg subtree + this plugin)
npm run env stop          # Stop it
```

The dev site is <http://localhost:8888> (tests site <http://localhost:8889>).
`autoPort` is enabled, so when those ports are busy — e.g. another wp-env
instance is running — wp-env automatically picks free ones and prints the URLs
it chose on startup. To force specific ports instead, set
`WP_ENV_PORT` / `WP_ENV_TESTS_PORT`.

### Quality

```bash
composer lint             # PHPCS (mirrors wordpress-develop)
composer format           # PHPCBF
npm run lint:js           # ESLint (mirrors Gutenberg)
npm run format            # Prettier (@wordpress/prettier-config)
```

### Tests

```bash
npm run test:js           # Jest — engines/providers + frozen-core vectors
npm run test:php          # PHPUnit in the wp-env tests container (loads the
                          # Gutenberg subtree as the framework, then the plugin)
npm run test:e2e          # Playwright — two-browser collaboration against the
                          # running env (needs `npx playwright install chromium`)
```

`npm run test:php` and `npm run test:e2e` require a running environment
(`npm run env start`) with the Gutenberg subtree built (see Setup).

The e2e specs reuse the Gutenberg subtree's collaboration fixtures, so they
must run against a single copy of `@playwright/test`; `pretest:e2e`
deduplicates the subtree's copy against this plugin's automatically. They run
against the tests site (`:8889`); if it landed on a different port (auto-port,
or an override), point them there with `WP_BASE_URL`, e.g.
`WP_BASE_URL=http://localhost:8890 npm run test:e2e`. If a *different*
project's wp-env holds `:8889`, always pass `WP_BASE_URL` — Playwright's
web-server check would otherwise silently reuse that foreign site.

`@wordpress/sync` is **externalized** at build time (the WordPress
dependency-extraction plugin maps `@wordpress/*` imports to the `wp.*`
runtime globals and adds them as script dependencies), so the plugin ships
no copy of the framework; the `file:` devDependency exists only for local
type-checking against the Gutenberg checkout.

### Benchmarks and tools

- `tests/benchmarks/` — a server-side engine benchmark harness: it drives any
  registered engine through the production ingest/read seam and reports
  service-time percentiles, payload and storage growth, and (for intent-log)
  merge-quality metrics; `compare.js` renders multiple runs side by side.
  See `tests/benchmarks/README.md` for how to run it and how to read the
  numbers.
- `tests/benchmarks/transport/` — a transport experience benchmark: two real
  browser clients measure edit-to-visible propagation latency and wire
  traffic (editing + idle) per transport. See its README.
- `tests/tools/` — Node CLI utilities: a long-running intent-log simulator
  sweep (`node tests/tools/sweep.js`), a manual two-tab sync observer against
  a live environment (`node tests/tools/observe-two-tab-sync.mjs`), and the
  frozen-core test-vector generators.

### Coding standards

- PHP: `phpcs.xml.dist` runs WordPress-Core/Extra/Docs + PHPCompatibilityWP,
  as `wordpress-develop` does.
- TypeScript/JS: `.eslintrc.js` extends `@wordpress/eslint-plugin` and
  `prettier.config.js` re-exports `@wordpress/prettier-config`, as Gutenberg
  does.

The frozen JS engine core under `src/engines/intent-log/` is a vendored
cross-language contract (byte-matched against its PHP twin and JSON vectors)
and is excluded from linting/formatting.
