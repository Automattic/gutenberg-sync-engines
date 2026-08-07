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
- JS: the `sync.engines` and `sync.transports` filters, consuming
  `@wordpress/sync`'s unlockable private APIs.

## Development

Requires the Gutenberg collaborative-editing framework at runtime.

```bash
composer install          # PHP tooling (PHPCS/WPCS, PHPUnit polyfills)
npm install               # JS tooling (@wordpress/scripts)

composer lint             # PHPCS (mirrors wordpress-develop)
composer format           # PHPCBF
npm run lint:js           # ESLint (mirrors Gutenberg)
npm run format            # Prettier (@wordpress/prettier-config)
npm run build             # Build the client bundle
```

`@wordpress/sync` is **externalized** at build time (the WordPress
dependency-extraction plugin maps `@wordpress/*` imports to the `wp.*`
runtime globals and adds them as script dependencies), so the plugin ships
no copy of the framework; the `file:` devDependency exists only for local
type-checking against the Gutenberg checkout.

### Coding standards

- PHP: `phpcs.xml.dist` runs WordPress-Core/Extra/Docs + PHPCompatibilityWP,
  as `wordpress-develop` does.
- TypeScript/JS: `.eslintrc.js` extends `@wordpress/eslint-plugin` and
  `prettier.config.js` re-exports `@wordpress/prettier-config`, as Gutenberg
  does.

The frozen JS engine core under `src/engines/intent-log/` is a vendored
cross-language contract (byte-matched against its PHP twin and JSON vectors)
and is excluded from linting/formatting.
