# M2.2 — Struct store & delete set

The two core data structures the CRDT operates over. Isolated and unit/fixture-verifiable.

## Goal

`StructStore` and `DeleteSet`, including delete-set encode/decode that byte-matches JS.

## Prerequisites

M2.1 (IDs & codecs).

## Implement

- [yjs/src/utils/StructStore.js](../../../yjs/src/utils/StructStore.js): the per-client struct arrays and `addStruct`, `find`, `findIndexSS`, `getItem`, `getItemCleanStart`, `getItemCleanEnd`, `getState`, `getStateVector`, `integrityCheck`, pending-structs fields.
- [yjs/src/utils/DeleteSet.js](../../../yjs/src/utils/DeleteSet.js): `DeleteSet`, `DeleteItem`, `createDeleteSet`, `createDeleteSetFromStructStore`, `iterateDeletedStructs`, `isDeleted`, `findIndexDS`, `mergeDeleteSets`, `equalDeleteSets`, `sortAndMergeDeleteSet`, `writeDeleteSet`/`readDeleteSet`, `addToDeleteSet`.

## Tests to turn green

- Unit tests for store lookup (`findIndexSS`, clean-start/clean-end split points) and delete-set merge/equality.
- **Conformance byte-fixtures**: `writeDeleteSet` output byte-matches real yjs for representative delete sets; `readDeleteSet` round-trips.

## Gotchas

- `findIndexSS` is a binary search that must match JS semantics exactly — off-by-one here corrupts every integrate later.
- `sortAndMergeDeleteSet` ordering (by clock, merging adjacent ranges) must match JS or delete-set bytes diverge.
- `getStateVector` client ordering feeds `encodeStateVector` later — keep it deterministic and JS-matching.

## Exit criterion

Store and delete-set unit tests green; delete-set encode/decode byte-matches real yjs; `composer lint` clean.
