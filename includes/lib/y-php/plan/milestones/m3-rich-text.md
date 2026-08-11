# M3 — Rich text: YText

## Goal

`YText` with formatting attributes, delta representation, and embeds — converging across clients and byte-matching JS.

## Prerequisites

M2 (CRDT core: Item, AbstractType, Transaction, Doc, encoding, YArray/YMap).

## Implement

- [yjs/src/types/YText.js](../../../yjs/src/types/YText.js) (~1298 lines — the largest single type) and `YTextEvent`.
- Confirm `ContentFormat` and `ContentEmbed` (ported in M2's `structs/` wave) behave correctly under text formatting; finish any text-specific paths.
- `cleanupYTextFormatting` and the delta APIs (`toDelta`, `applyDelta`, `insert` with attributes, `format`).
- The text **search-marker** logic for efficient indexed access — mirror the JS exactly.

## Tests to turn green

- `tests/Unit/TextTest` — unit **and** fuzz.
- `tests/Conformance/` — YText fixtures (including formatted runs and embeds) decode and re-encode byte-identically; seed-replayed fuzz converges to JS bytes.

## Gotchas

- **Formatting attributes** encode through `writeAny` — re-verify the number/null/undefined dispatch for attribute values.
- **Delta equality** in `compare()` uses a custom comparator; structural compare, not JSON-string compare (hazard 10).
- Formatting cleanup and the ordering of format vs. insert items is a common source of byte divergence — let the conformance fixtures pin it.

## Exit criterion

`y-text` unit + fuzz green; YText fixtures byte-match real JS in both directions; multi-user text convergence matches JS bytes for many seeds.
