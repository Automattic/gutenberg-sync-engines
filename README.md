# Gutenberg sync engines

Pluggable real-time collaboration **engines** and **transports** for Gutenberg.

Gutenberg hosts the collaboration *framework*: the `WP_Sync_Engine` /
`WP_Sync_Transport` / `WP_Sync_Storage` contracts, the two registries (server
and client), room permission config, storage, the client `@wordpress/sync`
package, and the editor/data-layer integration (including the conflict-review
UI). This plugin supplies the *implementations* that register themselves via
filters supplied by Gutenberg.

**Without this plugin active, real-time collaboration is effectively
disabled.** The framework registers no engine or transport, so a session
finds nothing to negotiate and the editor falls back to the classic
exclusive post lock.

## What it provides

Engines (how concurrent edits merge):

- **intent-log**: a server-authoritative log of typed intents; concurrent
  edits merge by transform, genuine conflicts are set aside for review, and
  no work is silently lost.
- **yjs-server**: a server-authoritative CRDT: the vendored y-php library
  merges every update into a canonical room document server-side, compacts
  by itself, and materializes post content.
- **de-rtc**: Distributed Editing's save-centric model: clients propose
  whole content against a named base version, the server three-way-merges
  every proposal. Genuine conflicts escalate instead of silently merging.

Transports (how updates move):

- **http-polling** — short-poll `POST /wp-sync/v1/updates` (default).
- **http-long-polling** — the same, held open until data is ready.
- **websocket** — push over a persistent socket served by a bundled PHP
  daemon (`wp collaboration sync-server`). For local dev, `npm run rtc:ws`
  starts everything in one command (and `npm run rtc:http` switches back).

The active engine and transport are chosen on the plugin's **Settings →
Collaboration** screen (or via `wp_sync_engine` / the
`WP_COLLABORATION_TRANSPORT` config value).

**Comparing the engines?** Start with [`docs/`](docs/README.md). The short
answer and the full trade-off — scorecard, feature parity, resource shapes,
and each engine's known gaps — live in
[`docs/engine-comparison.md`](docs/engine-comparison.md); the transports are
compared separately in [`docs/transports.md`](docs/transports.md). Both are
deliberately number-free. Run `npm run bench` for numbers on your hardware.

**Want to help?** [`plan/`](docs/plan/README.md) holds what we intend to build
next, one file per bug or feature, each with an example and a way to tell
when it is done. [`docs/plan/wontfix.md`](docs/plan/wontfix.md) covers what we looked
at and set aside, and why.

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

A modified copy of Gutenberg at runtime is vendored as a **git subtree** in
`gutenberg/` and mounted by `.wp-env.json` so the local WordPress environment
runs the exact Gutenberg the engines were built against. No separate checkout
needed.

### Setup

```bash
composer install          # PHP tooling (PHPCS/WPCS, PHPUnit + polyfills)
npm install               # JS tooling (@wordpress/scripts, wp-env, Playwright)
npm run build             # Build this plugin's client bundle

# Build the vendored Gutenberg.
cd gutenberg && npm install --ignore-scripts && npm run build && cd ..
```

### Environment

```bash
npm run env start         # Start WordPress (Gutenberg subtree + this plugin)
npm run env stop          # Stop it
```

### Tests

```bash
npm run test:js           # Jest — engines/providers + frozen-core vectors
npm run test:php          # PHPUnit in the wp-env tests container (loads the
                          # Gutenberg subtree as the framework, then the plugin)
npm run test:e2e          # Playwright — two-browser collaboration against the
                          # running env (needs `npx playwright install chromium`)
```

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
