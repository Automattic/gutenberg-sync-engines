# M2.1 — IDs & update codecs

First slice of the M2 core. Builds the struct-aware encoder/decoder layer that sits directly on top of `Lib0\Encoding`/`Decoding`. Isolated and byte-fixture-verifiable.

## Goal

`ID` plus the V1 update codecs, producing byte-identical output to JS for IDs, clients, clocks, and info bytes.

## Prerequisites

M0 (`Lib0\Encoding`/`Decoding` byte-match JS; harness + lint gate in place). M1 (stubs).

## Implement

- [yjs/src/utils/ID.js](../../../yjs/src/utils/ID.js): `ID`, `createID`, `compareIDs`, `findRootTypeKey` helpers.
- [yjs/src/utils/UpdateEncoder.js](../../../yjs/src/utils/UpdateEncoder.js) — **V1 only**: `DSEncoderV1` base and `UpdateEncoderV1` (`writeLeftID`, `writeRightID`, `writeClient`, `writeInfo`, `writeString`, `writeBuf`, `writeJSON`, `writeKey`, `writeDsClock`, `writeDsLen`).
- [yjs/src/utils/UpdateDecoder.js](../../../yjs/src/utils/UpdateDecoder.js) — **V1 only**: `DSDecoderV1`, `UpdateDecoderV1` (the symmetric readers).

Leave the V2 classes as M1 stubs (DEC-0003).

## Tests to turn green

- Unit tests for `createID`/`compareIDs`.
- **Conformance byte-fixtures**: encode an `ID`, a client/clock pair, an info byte, a DS clock/len via `UpdateEncoderV1` and assert byte-equality with values captured from real yjs; round-trip each through `UpdateDecoderV1`.

## Gotchas

- `writeClient`/`writeInfo` are where unsigned-32-bit handling (hazard 2) first meets struct data — re-verify against fixtures.
- Keep the V1/V2 class split intact even though V2 is stubbed; later `instanceof` checks and M7 depend on the shape.

## Exit criterion

`ID` unit tests green; all V1 codec primitives byte-match real yjs and round-trip; `composer lint` clean.
