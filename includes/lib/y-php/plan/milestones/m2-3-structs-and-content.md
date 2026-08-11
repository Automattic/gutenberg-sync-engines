# M2.3 — Structs & content

The struct base classes and the full `Content*` family. Highly parallelizable — each content type is an independent write/read unit testable by byte-fixtures.

## Goal

`AbstractStruct`, `GC`, `Skip`, and every `Content*`, each encoding/decoding byte-identically to JS.

## Prerequisites

M2.1 (codecs), M2.2 (store/delete set — `ContentDeleted` touches the delete set).

## Implement

- [yjs/src/structs/AbstractStruct.js](../../../yjs/src/structs/AbstractStruct.js), [GC.js](../../../yjs/src/structs/GC.js), [Skip.js](../../../yjs/src/structs/Skip.js).
- The `Content*` family in [yjs/src/structs/](../../../yjs/src/structs/): `ContentString`, `ContentJSON`, `ContentAny`, `ContentBinary`, `ContentEmbed`, `ContentFormat`, `ContentDeleted`, `ContentType`, `ContentDoc`. Each implements `getLength`, `getContent`, `isCountable`, `copy`, `splice`, `mergeWith`, `integrate`, `delete`, `gc`, `write`, and the module-level `readContent*`.

## Tests to turn green

- **Conformance byte-fixtures per content type**: encode a `ContentString`/`ContentAny`/`ContentJSON`/`ContentBinary`/`ContentEmbed`/`ContentFormat` and assert byte-equality with real yjs; round-trip each reader.
- `ContentAny` must exercise the full `writeAny` matrix (hazards 3/4/5) end-to-end.

## Gotchas

- **Forward dependency:** `ContentType` references `AbstractType` and `ContentDoc` references `Doc`, both of which land in M2.4. Implement their `write`/`read`/`getLength`/`copy` fully now; their `integrate`/`gc` interaction with live types is completed in M2.4. If you stub any method, **log it in DECISIONS** so M2.4 knows to finish it.
- Keep all nine content classes distinct (hazard 9) — the content-ref integer in `Item.write` selects the reader by exact type.
- `ContentString` length is UTF-16-unit based in JS; verify the PHP length convention matches what `Item` splitting expects (record the choice).

## Exit criterion

Struct/content unit + byte-fixture tests green for every content type that doesn't require a live type; any deferred `integrate`/`gc` paths logged for M2.4; `composer lint` clean.
