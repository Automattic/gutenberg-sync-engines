# M7 — V2 encoding (deferred)

**Deferred by DEC-0003.** Build only if a consumer actually requires the V2 wire format. Confirm that need before starting.

## Goal

The V2 update encoding and V1↔V2 conversion, reaching parity with the JS V2 paths.

## Prerequisites

M2–M5 (the full V1 stack), since V2 mirrors V1's structure with a different on-wire representation.

## Implement

- `UpdateEncoderV2` / `UpdateDecoderV2` and the `DSEncoderV2`/`DSDecoderV2` bases in [UpdateEncoder.js](../../../yjs/src/utils/UpdateEncoder.js) / [UpdateDecoder.js](../../../yjs/src/utils/UpdateDecoder.js). V2 uses run-length / diff-gap encoders for clients, clocks, and lengths.
- The `*V2` free functions: `applyUpdateV2`, `encodeStateAsUpdateV2`, `mergeUpdatesV2`, `diffUpdateV2`, `encodeSnapshotV2`/`decodeSnapshotV2`, `obfuscateUpdateV2`.
- `convertUpdateFormatV1ToV2` and `convertUpdateFormatV2ToV1` in [updates.js](../../../yjs/src/utils/updates.js).

## Tests to turn green

- The V2 branches of `tests/Unit/UpdatesTest` and any type test that runs under V2 encoding (`init()` randomly selects V2).
- `tests/Conformance/` — V2 fixtures byte-match real JS.

## Gotchas

- The V2 integer encoders (run-length, delta) have their own byte-exactness traps — treat them like a second M0 for the V2 format.
- Once V2 exists, the convergence harness can stop falling back to V1, exercising more of the sync surface.

## Exit criterion

V2 update/snapshot encoding byte-matches real JS; V1↔V2 conversion round-trips; V2-selected fuzz runs converge to JS bytes.
