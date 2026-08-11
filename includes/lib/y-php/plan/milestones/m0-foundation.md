# M0 — Foundation: lib0 primitives + harness skeleton

**This is a hard gate.** No later milestone starts until M0's encoding-primitives conformance test byte-matches JS. A wrong varint here surfaces as a baffling failure milestones later.

## Goal

Port the load-bearing binary primitives and stand up the test runner so anything can execute and be checked against real JS bytes.

## Prerequisites

None. This is the first milestone.

## What to build

**The byte buffer** — one wrapped representation standing in for `Uint8Array` (PHP binary `string` inside a `Buffer` class). Record the choice in DECISIONS; everything downstream uses it. → hazard 1.

**`src/Lib0/Encoding` + `src/Lib0/Decoding`**, ported from `yjs/node_modules/lib0/encoding.js` and `decoding.js`:
- `writeVarUint` / `readVarUint`, `writeVarInt` / `readVarInt`
- `writeVarString` / `readVarString` (byte-length prefix — hazard 7)
- `writeFloat32/64`, `writeBigInt64` (big-endian — hazard 6)
- `writeUint8Array` / `writeVarUint8Array` and reads
- `writeAny` / `readAny` (the number-dispatch and `undefined`/`null`/`bigint` cases — hazards 3, 4, 5)

**`src/Lib0/Binary`** (bit masks: `BIT1..BIT32`, `BITS31`, etc.) and **`src/Lib0/Buffer`** (base64, copy helpers). The unsigned-32-bit `>>>` helper lives here or in Encoding — record where. → hazard 2.

**Utility shims** (small, port as needed): `Math`, `Arr`, `Mp` (map), `St` (set), `Obj`, `Func`, `Iterator`, `Error`, `Observable` (event emitter), `Random`, `Time`, `Str`.

**`src/Lib0/Prng`** — Xoroshiro128plus + Xorshift32, ported **exactly**, plus `bool`, `oneOf`, `int32`, `word`, `uint32`. Exactness is non-negotiable: M2+ fuzz tests rely on the same seed producing the same scenario as JS.

**Harness + tooling wiring:**
- `composer.json` — PSR-4 autoload `Yjs\` → `src/`; dev deps `phpunit/phpunit`, `squizlabs/php_codesniffer`, `wp-coding-standards/wpcs` (^3), `phpcompatibility/phpcompatibilitywp`, `dealerdirect/phpcodesniffer-composer-installer`; scripts `test` → `phpunit`, `lint` → `phpcs`, `lint:fix` → `phpcbf`.
- `phpcs.xml.dist` at repo root — the WPCS-minus-naming ruleset from CONTEXT (`testVersion` `7.4-`, `PHPCompatibilityWP`, `WordPress` with the three naming/file sniffs excluded). This is what enforces DEC-0004 (7.4 floor) and DEC-0005 (camelCase) for every later milestone.
- A minimal `t`-style assertion shim in `tests/Support/` (`compare`, `compareArrays`, `assert`, `fails`).
- `tools/gen-fixtures.mjs` scaffolding that can dump primitive-encoding fixtures from real lib0.

Write all of this to the **PHP 7.4 baseline** (CONTEXT "PHP language baseline") — the foundation classes are the reference example later agents will copy, so they must already be clean: no enums, no readonly, PHPDoc for union types, typed properties and arrow functions OK.

## Tests to turn green

- A conformance test that round-trips every primitive writer **and** asserts byte-equality against fixtures captured from real lib0 (`writeVarUint`, `writeVarInt`, `writeVarString`, `writeAny` across strings/ints/floats/null/arrays/objects/Uint8Array, `writeFloat32/64`).
- A PRNG test asserting the first N outputs for a fixed seed match values captured from `lib0/prng`.

## Exit criterion

`Lib0\Encoding`/`Decoding` byte-match captured JS output for **all** primitive writers, and the PRNG matches JS for a fixed seed. `composer test` runs green on the foundation suite, and `composer lint` passes clean (the ruleset is now the gate for every later milestone).

## Decisions you must log

Byte-buffer class + API; location/shape of the `>>>` helper; `undefined` (127) decode mapping; `bigint` handling; float pack codes; PRNG class names.
