# M5 — Updates, snapshots, positions, history

## Goal

The update-manipulation toolkit and history features: merging/diffing updates, snapshots, relative positions, and undo/redo.

## Prerequisites

M2–M4 (all core types and the encoding core).

## Implement

- [yjs/src/utils/updates.js](../../../yjs/src/utils/updates.js) (~722 lines): `mergeUpdates`, `diffUpdate`, `encodeStateVectorFromUpdate`, `parseUpdateMeta`, `obfuscateUpdate`. **V1 only** — V1↔V2 conversion is M7 (DEC-0003).
- [yjs/src/utils/Snapshot.js](../../../yjs/src/utils/Snapshot.js): `snapshot`, `createSnapshot`, `encodeSnapshot`/`decodeSnapshot`, `equalSnapshots`, `createDocFromSnapshot`, `snapshotContainsUpdate`.
- [yjs/src/utils/RelativePosition.js](../../../yjs/src/utils/RelativePosition.js) (full): `createRelativePositionFromTypeIndex`, `createAbsolutePositionFromRelativePosition`, `encodeRelativePosition`/`decode`, `compareRelativePositions`.
- [yjs/src/utils/UndoManager.js](../../../yjs/src/utils/UndoManager.js): full undo/redo with stack-item tracking.
- [yjs/src/utils/PermanentUserData.js](../../../yjs/src/utils/PermanentUserData.js) (lower priority — sequence last).

## Tests to turn green

- `tests/Unit/UpdatesTest`, `SnapshotTest`, `RelativePositionsTest`, `UndoRedoTest` — unit **and** fuzz where present.
- `tests/Conformance/` — `mergeUpdates`, `encodeStateVectorFromUpdate`, and `encodeSnapshot` outputs byte-match real JS.

## Gotchas

- `mergeUpdates` must produce **byte-identical** output to JS, not merely a semantically-equivalent merge — it operates directly on the lazy struct/DS encoders.
- `diffUpdate` and `encodeStateVectorFromUpdate` parse updates without a full Doc; the lazy decoder paths must match.
- Snapshot encoding folds in the delete set and state vector — reuse the M2 encoders, don't fork them.

## Exit criterion

`updates`, `snapshot`, `relativePositions`, `undo-redo` tests green; merge/diff/snapshot/state-vector outputs byte-match real JS.
