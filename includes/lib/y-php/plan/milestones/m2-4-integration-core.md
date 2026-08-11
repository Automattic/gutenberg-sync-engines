# M2.4 — Integration core (Item, AbstractType, Transaction, Doc, encoding)

**The keystone of the whole port.** These pieces are co-dependent — they only come alive together, so they're one task even though it's the largest. This is where the CRDT actually runs and where M0–M2.3's byte-compat work is proven under load. Budget accordingly; consider one focused agent with sub-sequencing below.

## Goal

A live `Doc` that can ingest any JS-produced update and re-emit it byte-identically, with `AbstractType`'s generic list/map machinery in place. After this, `doc.tests` pass.

## Prerequisites

M2.1 (codecs), M2.2 (store/delete set), M2.3 (structs/content — including any `integrate`/`gc` paths it deferred to here).

## Implement, in this internal order

1. [yjs/src/structs/Item.js](../../../yjs/src/structs/Item.js) (~816 lines) — `integrate` (the origin/left/right/rightOrigin conflict resolution), `markDeleted`, `splitItem`, `mergeWith`, `keepItem`, `gc`, `write`. The algorithmic heart; mirror the JS control flow line-for-line.
2. [yjs/src/utils/Transaction.js](../../../yjs/src/utils/Transaction.js) — `transact`, change tracking, `tryGc`, the delete-set/merge bookkeeping, after-transaction cleanup and event scheduling.
3. [yjs/src/types/AbstractType.js](../../../yjs/src/types/AbstractType.js) (~985 lines) — the `_start` linked-list management, `typeListInsertGenerics`, `typeListDelete`, `typeMapSet`/`typeMapGet`/`typeMapDelete`, search-marker logic, integrate. Plus [EventHandler.js](../../../yjs/src/utils/EventHandler.js) and [YEvent.js](../../../yjs/src/utils/YEvent.js).
4. [yjs/src/utils/Doc.js](../../../yjs/src/utils/Doc.js) — `getMap`/`getArray`/`getText`/`get`, `transact`, update/destroy/subdocs events, `clientID`.
5. [yjs/src/utils/encoding.js](../../../yjs/src/utils/encoding.js) (~644 lines) — `readStructs`, `integrateStructs`, `resumeStructs`/pending handling, `readClientsStructRefs`, `applyUpdateV2`/`readUpdateV2` (V1 path), `encodeStateAsUpdate`, `encodeStateVector`.
6. The `y-protocols/sync` subset the convergence harness needs: `writeSyncStep1`, `writeSyncStep2`, `readSyncMessage`, `writeUpdate`.

## Tests to turn green

- `tests/Unit/DocTest` (the `doc.tests.js` port) — unit and its fuzz portions.
- **Round-trip conformance** (the headline result): load JS-generated update fixtures, `applyUpdate`, and assert `encodeStateVector` **and** a re-`encodeStateAsUpdate` byte-match the fixture. This proves lossless wire ingest/emit *before* any user-facing type API exists.

## Gotchas

- `Item.integrate` conflict resolution is the single most error-prone port in the project. Do not "simplify"; when unsure, replicate the JS exactly and let round-trip fixtures arbitrate.
- **Search markers** in `AbstractType` are a performance optimization with subtle invalidation rules — porting them wrong yields wrong indices, not just slowness.
- Pending-structs handling (updates that arrive before their dependencies) is easy to miss; `doc.tests` and the convergence harness exercise it.
- AbstractType here carries the shared list/map logic that YArray (M2.5) and YMap (M2.6) build on — get the generics right so those become thin.

## Exit criterion

`doc` tests green; PHP losslessly ingests and re-emits JS update fixtures byte-identically; state vectors byte-match; `composer lint` clean.
