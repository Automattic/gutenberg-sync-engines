# M6 — Interop hardening

## Goal

Prove the port works against **real** JS clients, not just the fixture oracle, and pass backward-compatibility tests.

## Prerequisites

M2–M5 (full V1 feature set green).

## Do

- **Cross-decode real browser updates:** capture updates produced by an actual browser Yjs client (or `y-websocket` session) and confirm PHP `applyUpdate` reproduces the expected state; confirm PHP-produced updates apply cleanly in a real JS client.
- **`tests/Unit/CompatibilityTest`** — port and pass [yjs/tests/compatibility.tests.js](../../../yjs/tests/compatibility.tests.js), which checks decoding of updates produced by older yjs versions.
- **Subdocuments** (`ContentDoc`) end-to-end if not already covered — nested-doc updates and the `subdocs` transaction events.
- **Op-log bridge (Layer 3) — only if needed.** If Layer-2 fixtures prove too coarse to localize a bug, build the operation-log bridge: instrument the JS `Y` façade to record public-API calls as a serializable script, replay against the PHP CLI, diff artifacts. Skip if fixtures suffice.

## Tests to turn green

- `tests/Unit/CompatibilityTest`.
- A small set of real-client round-trip checks (captured `.bin` updates from a browser session) under `tests/Conformance/`.

## Gotchas

- Older-version updates may use encoding quirks the current tests don't exercise; the decoder must be tolerant where JS is.
- Real-client captures are the truest test of hazard fixes — if one fails, suspect an unsigned-int or float-endianness edge before suspecting CRDT logic.

## Exit criterion

`compatibility` tests green; real browser-generated updates decode correctly and PHP-generated updates apply in a real JS client.
