# M2.6 — YMap

Second public type and the milestone that completes M2. Mirrors M2.5's shape on the map machinery.

## Goal

The full `YMap` public API, converging across clients and byte-matching JS.

## Prerequisites

M2.4 (integration core — `AbstractType` holds the generic map machinery). Can run in parallel with M2.5.

## Implement

- [yjs/src/types/YMap.js](../../../yjs/src/types/YMap.js): `set`, `get`, `delete`, `has`, `clear`, `keys`, `values`, `entries`, `forEach`, the iterator, `toJSON`, `size`, `YMapEvent`, and observer wiring on top of AbstractType's `typeMapSet`/`typeMapGet`/`typeMapDelete`.

As with YArray, most behavior lives in `AbstractType` from M2.4; this is the public surface, the event type, and map-specific edge cases (overwriting a key keeps the prior item as deleted history, key iteration order, deleted-key visibility).

## Tests to turn green

- `tests/Unit/MapTest` (the `y-map.tests.js` port, 40 tests) — unit **and** fuzz (`applyRandomTests`).
- `tests/Conformance/` — YMap fixtures decode and re-encode byte-identically; seed-replayed fuzz converges to JS bytes.

## Gotchas

- **Key iteration order** must match JS (hazard 8) — `keys()`/`toJSON()` order feeds the convergence `compare()` and any object-encoding bytes.
- Overwriting a key creates a new item and marks the old one deleted; `size` and `has` must reflect only live entries while the deleted chain stays in the store for convergence.

## Exit criterion

`y-map` unit + fuzz green; YMap fixtures byte-match real yjs both directions; multi-user convergence matches JS bytes for many seeds; `composer lint` clean. **With M2.5, this completes M2** — the structured-data core is done.
