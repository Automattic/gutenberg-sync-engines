# Porting status — client side

The server half of the plugin (engines, transports, settings, PHP tests) is
complete and self-contained: it registers through the framework's existing
`wp_sync_engines` / `wp_sync_transports` filters and needs no Gutenberg
changes.

The client half is **relocated but not yet buildable**. It depends on a
coordinated Gutenberg (`@wordpress/sync`) change to expose the framework's
registration surface as unlockable private APIs and to stop shipping default
engines/transports. This file tracks exactly what remains.

## 1. Gutenberg (`@wordpress/sync`) — expose an unlockable surface — DONE

`@wordpress/sync` already ships a `privateApis` export (via
`@wordpress/private-apis`; `@wordpress/sync` is in the allowlist and there is
no double-registration guard, so this plugin unlocks it by claiming the
`@wordpress/sync` module name — see `src/lock-unlock.ts`). It now also
exposes:

- `registerSyncEngine( adapter: SyncEngineAdapter )` — appends to the engine
  adapter registry.
- `registerSyncTransport( registration: TransportRegistration )` — appends to
  the transport registry.

(Plus the pre-existing `createSyncManager`, `resolveEngineAdapter`, etc. The
`EngineSessionCodec` base/types are exported from the package types; the wire
types/base64 helpers still need surfacing where the providers import them.)

**Still pending:** remove the built-in default adapters/transports
(`getDefaultEngineAdapters` / `getDefaultTransports`) so that, without this
plugin, the registries are empty and RTC degrades to the post lock — this is
the destabilizing step (it moves the adapter/provider code + jest suites out
of `@wordpress/sync` into this plugin), done together with §2.

## 2. This plugin — rewrite relative imports to the framework

The moved files under `src/engines/` and `src/providers/` still import
framework internals by their original relative paths (e.g. `../../types`,
`../session`, `../../engines`). Repoint each to `@wordpress/sync` (public) or
to the unlocked `privateApis` surface. The frozen core under
`src/engines/intent-log/` is standalone and needs no changes.

Also add the two thin adapter factories referenced by `src/index.ts`:

- `src/engines/intent-log-adapter.ts` — wraps `createIntentLogManager` from
  `src/engines/intent-log-manager.ts` into a `SyncEngineAdapter`.
- `src/engines/yjs-relay-adapter.ts` — wraps the framework's
  `createSyncManager`.

(These were previously assembled inline in `@wordpress/sync`'s `engines.ts`
`getDefaultEngineAdapters()`; they move here.)

## 3. Build wiring

- `webpack.config.js` extends `@wordpress/scripts` and relies on
  `@wordpress/dependency-extraction-webpack-plugin` to externalize
  `@wordpress/*` (so `@wordpress/sync` → `wp.sync`, added as a script dep).
- Enqueue the built `build/index.js` on the block editor when the framework
  reports collaboration is enabled, and enqueue the raw
  `includes/engines/intent-log/sync-id.js` genesis stamper (moved from the
  framework's `collaboration.php`).

## 4. Client tests

Move the jest suites for the adapters/providers (manager, bridge, session,
negotiation, ws-manager) into `src/**/test/` and run via
`wp-scripts test-unit-js`. They pass today in the Gutenberg checkout; they
travel here once the imports above resolve.
