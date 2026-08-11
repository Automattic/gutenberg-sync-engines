# M1 — Stub the entire public API + translate the tests (red baseline)

## Goal

Generate PHP skeletons for the whole public API and translate all 208 tests so the **suite runs and fails for the right reason** (`NotImplemented`, not parse errors). This is the red baseline that makes red→green meaningful.

## Prerequisites

M0 complete (encoding primitives byte-match JS; harness runs).

## What to build

**API stubs** — one PHP class/function per export in `yjs/src/index.js`:
- Classes: `Doc`, `Transaction`, `Item`, `AbstractStruct`, `GC`, `Skip`, every `Content*` (`ContentString/JSON/Any/Binary/Embed/Format/Deleted/Type/Doc`), `AbstractType`, `YArray`, `YMap`, `YText`, `YXmlText/Hook/Element/Fragment`, every `*Event`, `ID`, `Snapshot`, `RelativePosition`, `AbsolutePosition`, `AbstractConnector`, `UndoManager`, `PermanentUserData`, `StructStore`, `DeleteSet`, `UpdateEncoderV1/V2`, `UpdateDecoderV1/V2`.
- Free functions: `applyUpdate`, `readUpdate`, `encodeStateAsUpdate`, `encodeStateVector`, `mergeUpdates`, `diffUpdate`, `snapshot`, `createSnapshot`, `createRelativePositionFromTypeIndex`, `createAbsolutePositionFromRelativePosition`, `transact`, `createID`, `compareIDs`, `equalDeleteSets`, etc. — the full `index.js` list.
- Each stub throws a `NotImplemented` exception. Wire them through `src/Yjs.php` as the façade.

**Port the test harness** into `tests/Support/` — `TestConnector`, `TestYInstance`, `init()`, and `compare(users)` from `yjs/tests/testHelper.js`. These reference `Doc` and the sync protocol, so they compile against stubs and fail at runtime — that's expected at this stage.

**Translate the test files** — each `yjs/tests/*.tests.js` → one PHPUnit class in `tests/Unit/`. Keep test names and structure 1:1 so coverage is auditable. Translation is mostly mechanical (`t.compare(arr.toArray(), [1,2,3])`).

**Extend `tools/gen-fixtures.mjs`** to drive the real test scenarios and dump update bytes / state vectors / snapshots / `toJSON()` into `tests/fixtures/`.

## Tests to turn green

None — the goal is the opposite. **All ~208 tests must run and fail with `NotImplemented`** (or a documented skip), with zero parse/compile errors.

## Gotchas

- **Adapt or skip JS-only tests** with a documented reason (log in DECISIONS). Example: `testFailsObjectManipulationInDevMode` relies on `Object.freeze`; PHP arrays are copy-on-write value types, so there is nothing to freeze.
- The sync-protocol subset that `testHelper` needs (`y-protocols/sync`: `writeSyncStep1`, `readSyncMessage`, `writeUpdate`) gets stubbed here and implemented as part of M2's convergence work.

## Exit criterion

Full API stubbed; all test files translated; `composer test` runs the entire suite to completion with every test failing for the right reason (`NotImplemented`) or explicitly skipped with a logged reason.
