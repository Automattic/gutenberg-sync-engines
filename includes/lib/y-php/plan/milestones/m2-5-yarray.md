# M2.5 — YArray

First public type. Once the integration core runs, fleshing out a type's surface API and turning its test file green is an isolated task.

## Goal

The full `YArray` public API, converging across clients and byte-matching JS.

## Prerequisites

M2.4 (integration core — `AbstractType` already holds the generic list machinery).

## Implement

- [yjs/src/types/YArray.js](../../../yjs/src/types/YArray.js): `insert`, `push`, `unshift`, `delete`, `get`, `slice`, `toArray`, `toJSON`, `forEach`, `map`, the iterator, `Array.from`, `YArrayEvent`, and observer wiring on top of AbstractType's `typeListInsertGenerics`/`typeListDelete`.

Most behavior lives in `AbstractType` from M2.4; this milestone is the public surface, the event type, and edge cases (negative slice indices, nested types, length tracking under concurrent edits).

## Tests to turn green

- `tests/Unit/ArrayTest` (the `y-array.tests.js` port, 42 tests) — unit **and** fuzz (`applyRandomTests`).
- `tests/Conformance/` — YArray fixtures decode and re-encode byte-identically; seed-replayed fuzz converges to JS bytes.

## Gotchas

- The `length` vs `toArray().length` invariant under interleaved insert/delete (see `testLengthIssue`) stresses the search-marker logic from M2.4 — failures here often point back to M2.4, not YArray.
- Nested types (a YArray/YMap inside the array) round-trip through `ContentType`; confirm the M2.3/M2.4 type integration holds.

## Exit criterion

`y-array` unit + fuzz green; YArray fixtures byte-match real yjs both directions; multi-user convergence matches JS bytes for many seeds; `composer lint` clean.
