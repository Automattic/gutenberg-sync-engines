# Porting status — client side

The server half of the plugin (engines, transports, settings, PHP tests) is
complete and self-contained: it registers through the framework's existing
`wp_sync_engines` / `wp_sync_transports` filters and needs no Gutenberg
changes.

The client half is **relocated, buildable, type-checked, and fully tested**
against the framework consumed as the runtime `wp.sync` global. What remains is
the destabilizing *framework* cutover — removing the client code that still
lives in `@wordpress/sync` as a duplicate — which is deferred (see §5).

## 1. Gutenberg (`@wordpress/sync`) — expose an unlockable surface — DONE

`@wordpress/sync` ships a `privateApis` export (via `@wordpress/private-apis`;
`@wordpress/sync` is in the allowlist and there is no double-registration
guard, so this plugin unlocks it by claiming the `@wordpress/sync` module name
— see `src/lock-unlock.ts`). It exposes `registerSyncEngine`,
`registerSyncTransport`, `getProviderCreators`, `createSyncManager`,
`resolveEngineAdapter`, `ConnectionError`/`ConnectionErrorCode`, plus the
registry test-support helpers (`getEngineAdapters`,
`resetEngineAdaptersForTesting`, `resetProviderCreatorsForTesting`). The
engine/transport seam types are public package exports.

## 2. This plugin — imports repointed to the framework — DONE

The moved files under `src/engines/` and `src/providers/` import the framework
through `@wordpress/sync` (public types) and `src/framework.ts` (the private
runtime surface, unlocked once). The frozen core under
`src/engines/intent-log/` is standalone. The two adapter factories referenced
by `src/index.ts` exist: `src/engines/intent-log-adapter.ts` and
`src/engines/yjs-relay-adapter.ts`.

## 3. Build wiring — DONE

`webpack.config.js` extends `@wordpress/scripts` and externalizes
`@wordpress/sync` → `wp.sync` and `yjs` → `wp.sync.Y` (one shared Yjs
instance). The bundle's `asset.php` correctly declares `wp-sync`. The plugin's
PHP enqueues the built bundle and the `sync-id.js` genesis stamper.

## 4. Client tests — DONE

All adapter/provider/frozen-core jest suites live under `src/**/test/` and pass
via `wp-scripts test-unit-js` (298 tests / 20 suites). `jest.config.js`
resolves the framework from the sibling checkout, dedupes the single-instance
packages, and polyfills the globals jsdom omits.

## 5. Remaining: framework cutover — DEFERRED (Option A)

Not yet done: strip `@wordpress/sync`'s built-in defaults
(`getDefaultEngineAdapters` / the default transports) to empty and delete the
client code that now lives here as a duplicate, so that without this plugin the
client registries are empty (RTC already degrades to the post lock because the
*server* registries are empty by default). This is the destabilizing step and
its full proof is the cross-repo browser e2e (the sibling plugin mounted into
Gutenberg's e2e wp-env).

**Decision (Option A, for now):** relocate/remove only the **intent-log**
client duplicate and keep **yjs-relay** in the framework. Rationale below.

### TODO — refactor `createSyncManager` to be engine-neutral, then extract yjs-relay

`@wordpress/sync`'s `manager.ts` `createSyncManager()` is **not** engine-neutral
today: it **hardcodes `createYjsSessionCodec({ awareness, doc: ydoc })`**
(around lines 299 and 439) and constructs its own `Y.Doc`. It never calls the
adapter's `createSessionCodec`. Consequences:

- The generic manager the plugin's yjs adapter consumes (`createManager:
  createSyncManager`) **is** the yjs-relay manager. Deleting
  `engines/yjs-relay` from the framework would break `manager.ts`.
- The yjs adapter's `createSessionCodec` field is effectively dead — nothing
  in `createSyncManager` invokes it.

To fully honor "the plugin hosts the engines" for yjs-relay:

1. Refactor `createSyncManager` to consume the resolved adapter's
   `createSessionCodec` factory (and its doc/awareness construction) instead of
   importing `createYjsSessionCodec` directly — i.e. make the manager
   engine-neutral and codec-injected.
2. Move the yjs session codec + Y.Doc machinery into this plugin's
   `src/engines/yjs-relay/`, wiring it through the (now-live) adapter
   `createSessionCodec`.
3. Update `manager.ts`'s jest suite for the injected-codec seam; keep the
   frozen intent-log contract untouched.
4. Then apply §5's default-strip + client-code deletion for **both** engines
   and verify via the cross-repo e2e.

Until then, yjs-relay stays in the framework as deep-integration client code,
and only intent-log + the pluggable transports are sole-homed here.
