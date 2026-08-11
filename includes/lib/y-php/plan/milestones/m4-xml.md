# M4 — XML types

## Goal

The `YXml*` family, converging across clients and byte-matching JS.

## Prerequisites

M3 (YText — `YXmlText` extends the text machinery).

## Implement

- [yjs/src/types/YXmlFragment.js](../../../yjs/src/types/YXmlFragment.js), [YXmlElement.js](../../../yjs/src/types/YXmlElement.js), [YXmlText.js](../../../yjs/src/types/YXmlText.js), [YXmlHook.js](../../../yjs/src/types/YXmlHook.js).
- `YXmlEvent` and the XML tree-walking / `toString()` / `toDOM`-equivalent serialization (server-side: string output, no DOM).

## Tests to turn green

- `tests/Unit/XmlTest` — unit **and** fuzz.
- `tests/Conformance/` — YXml fixtures decode and re-encode byte-identically; seed-replayed fuzz converges to JS bytes.

## Gotchas

- `YXmlText` reuses YText internals — make sure no text-specific assumption from M3 breaks when wrapped as XML text.
- `YXmlHook` stores arbitrary map data; its `writeAny` path must round-trip.
- `toString()` output ordering of attributes must match JS for the string-comparison assertions in `compare()`.

## Exit criterion

`y-xml` unit + fuzz green; YXml fixtures byte-match real JS in both directions; multi-user convergence matches JS bytes for many seeds.
