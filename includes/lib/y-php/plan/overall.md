# Yjs → PHP Migration Plan

A port of [Yjs](https://github.com/yjs/yjs) (v13.6.31) to PHP for server-side use in WordPress.

## Decisions locked in

| Decision | Choice | Consequence |
|---|---|---|
| Interop | **Full binary wire-interop** with live JS clients | Byte-level conformance against real JS output is a P0 requirement, not a nice-to-have. |
| Type scope | **All core types** (Text, Array, Map, Xml*) | Full parity target; no permanent feature cuts, only sequencing. |
| PHP target | **PHP 7.4+ floor** | WordPress version compatibility. No enums/`readonly`/union-types; PHPDoc for types. Typed properties + arrow functions OK. (DEC-0004.) |
| Coding standard | **WPCS minus naming** | WordPress Coding Standards via PHPCS, excluding naming/file-name sniffs so the camelCase Yjs API is preserved. (DEC-0005.) |
| Test strategy | **Hybrid** | Translate JS tests to PHP for the TDD loop **and** generate binary fixtures from real JS yjs for byte-for-byte checks. |

## The core insight that shapes everything

Yjs is a CRDT whose correctness is defined by a **binary wire format**, not by an API. Two facts drive the whole plan:

1. **The format is the contract.** A WordPress server is useless unless it can decode updates produced by browser clients and produce updates browsers can decode. So the foundational, highest-risk work is the binary encoder/decoder (`lib0/encoding` + `lib0/decoding` and the Yjs `UpdateEncoder`/`UpdateDecoder` layered on top), and the foundational test is *"do PHP bytes equal JS bytes."*

2. **The PRNG is portable.** Yjs's fuzz tests are the real correctness engine, and they run on `lib0/prng`'s **Xoroshiro128plus** (seeded via Xorshift32) — a deterministic algorithm we can port exactly. That means **the same seed produces the same fuzz scenario in PHP and in JS.** This turns the fuzz suite into a true differential oracle: a divergence localizes cleanly to either CRDT logic or byte encoding.

Everything below is organized to exploit these two facts.

## Scope of the source

- **~11,200 lines** of JS across [yjs/src/](yjs/src/): `structs/` (the CRDT items + content variants), `types/` (Text/Array/Map/Xml), `utils/` (Doc, Transaction, StructStore, DeleteSet, encoding, updates, Snapshot, RelativePosition, UndoManager, …).
- **208 test functions** across 11 files in [yjs/tests/](yjs/tests/).
- A **`lib0` dependency surface** that must be ported first. By import frequency: `error`, `map`, `encoding`, `decoding`, `array`, `math`, `logging`, `set`, `observable`, `object`, `function`, `binary`, `iterator`, `time`, `string`, `random`, `buffer`, plus `prng` (tests only). Most are tiny utility shims; `encoding`/`decoding`/`binary`/`buffer` are the load-bearing ones.

## Repository layout

```
y-php/
├── composer.json            # PSR-4 autoload "Yjs\\" -> src/, PHPUnit, dev deps
├── src/
│   ├── Lib0/                # ported lib0 primitives (Encoding, Decoding, Binary, ...)
│   ├── Structs/             # AbstractStruct, Item, GC, Skip, Content*
│   ├── Types/               # AbstractType, YArray, YMap, YText, YXml*
│   ├── Utils/               # Doc, Transaction, StructStore, DeleteSet,
│   │                        #   UpdateEncoder, UpdateDecoder, Encoding, Updates,
│   │                        #   Snapshot, RelativePosition, UndoManager, EventHandler
│   └── Yjs.php              # public façade mirroring yjs/src/index.js exports
├── tests/
│   ├── Support/             # ported testHelper: TestConnector, TestYInstance, compare()
│   ├── Unit/                # translated *.tests.js  (one PHP class per JS file)
│   ├── Conformance/         # fixture-driven byte-for-byte checks vs JS oracle
│   └── fixtures/            # *.bin + *.json captured from real JS yjs (generated)
├── tools/
│   └── gen-fixtures.mjs     # Node script: drives real yjs, dumps fixtures
└── plan/
    └── overall.md           # this file
```

The PHP library is namespaced `Yjs\` and ships as a Composer package so WordPress plugins can `require` it.

## Testing architecture (the part you asked about first)

**What "running the full JS spec against PHP" does and doesn't mean.** We cannot literally execute the unmodified [yjs/tests/](yjs/tests/) `.tests.js` functions with PHP objects swapped in, and the plan does not pretend to. Those tests treat Yjs values as live, in-process JS objects and reach into internals — synchronous observer callbacks, iterators, `instanceof Y.AbstractType` checks, and private fields like `store.pendingDs` (see `compare()` in [testHelper.js](yjs/tests/testHelper.js)). Driving that from PHP would mean proxying the entire stateful internal API back into Node, synchronously, thousands of times per test. Impractical and brittle.

What we **can** do — and what gives the equivalent guarantee for a byte-interop server — is run **the full spec's behavior and every binary artifact it produces** against PHP, by comparing on *serializable artifacts* (bytes + JSON) instead of live objects. Four layers, in priority order. The guarantee lives in Layer 2; Layer 1 is the dev loop; Layers 3–4 are escalations we may not need.

### Layer 1 — Translated unit + fuzz tests (the TDD loop)

Port the JS test foundation so the `.tests.js` files can be re-expressed as PHP and run red→green as we implement. This is a *paraphrase* of the spec, so it carries **drift risk** — the translated PHP could quietly diverge from the JS source. That risk is closed by Layer 2, which validates against real JS output.

What must be ported to make tests *runnable*:

- **A byte buffer** standing in for `Uint8Array` (PHP binary `string`, or an `ArrayBuffer`-like wrapper). This is the single most important primitive.
- **`Lib0\Encoding` / `Lib0\Decoding`** — `writeVarUint`, `writeVarInt`, `writeVarString`, `writeFloat32/64`, `writeAny`, `writeUint8Array`, and their read counterparts.
- **`Lib0\Prng`** — Xoroshiro128plus + Xorshift32, plus `prng.bool`, `prng.oneOf`, `prng.int32`, `prng.word`, etc. Ported **exactly** so seeds reproduce JS scenarios.
- **A minimal `t` assertion module** mirroring the slice of `lib0/testing` the tests use: `compare`, `compareArrays`, `compareStrings`, `assert`, `fails`, `info`, `group`. Backed by PHPUnit assertions.
- **`tests/Support/` testHelper** — the [TestConnector / TestYInstance](yjs/tests/testHelper.js) network simulator and the `compare(users)` convergence check (which asserts identical `toJSON()`, state vectors, snapshots, delete sets, and struct stores across all users). This is what makes `applyRandomTests` work.

Translation is mostly mechanical because the assertions are simple (`t.compare(arr.toArray(), [1,2,3])`). Each [yjs/tests/*.tests.js](yjs/tests/) becomes a PHPUnit test class. The fuzz tests (`applyRandomTests`) port directly and, thanks to the exact PRNG, exercise *the same* operation sequences as JS.

**Caveat to flag:** a few tests assert JS-only semantics — e.g. `testFailsObjectManipulationInDevMode` relies on `Object.freeze` (PHP arrays are copy-on-write value types, so there's nothing to freeze). These get adapted or skipped with a documented reason rather than force-fit.

### Layer 2 — Running the full JS spec against PHP (seed replay + artifact fixtures) — **P0 for interop**

The guarantee you asked about. Two complementary mechanisms, both driven off the **real, unmodified** JS suite as the oracle. Together they run the full spec's behavior against PHP and are the **anti-drift backstop** for Layer 1: if a translated test ever disagrees with the JS source, the comparison against real JS output catches it.

**(a) Seed-reproducible fuzz scenarios — the strong mechanism.** The fuzz tests (`applyRandomTests`) are the bulk of yjs's real coverage and are driven *entirely* by `lib0/prng` from a seed. Because we port Xoroshiro128plus + Xorshift32 **exactly**, the same seed regenerates the identical operation sequence and network-flush schedule in PHP. PHP runs the same scenarios the JS spec runs and must converge to byte-identical updates / state-vectors / snapshots. This executes the real spec's *logic* against PHP, not a paraphrase of it.

**(b) Differential artifact fixtures.** A Node script, `tools/gen-fixtures.mjs`, imports the **real** yjs, runs the actual test scenarios, and dumps each one's **update bytes**, **state vector**, **snapshot**, and **`toJSON()`** to `tests/fixtures/`. PHP conformance tests then assert:

- **Decode:** PHP `applyUpdate(fixtureBytes)` yields the fixture's JSON state. (Can PHP read JS?)
- **Encode:** PHP running the same ops produces **byte-identical** update / state-vector / snapshot bytes. (Can JS read PHP? Byte-identity is the strong, testable proxy.)
- **Round-trip:** decode → re-encode → identical bytes.

This is the layer that actually guarantees a browser client and the WP server can collaborate. It catches encoding bugs the semantic unit tests never would (e.g. a varint that's off by a continuation bit but still parses).

### Layer 3 — Operation-log bridge (defined option, build if Layer 2 proves too coarse)

The middle ground between fixtures and live proxying, for when we want the *actual* deterministic JS tests to drive PHP directly. Instrument the JS `Y` façade to record every public-API call as a serializable script (method + args). Replaying that exact script against the PHP CLI and diffing the resulting artifacts runs the genuine JS test sequence against PHP **without** proxying live objects. Feasible for the deterministic tests; the fuzz tests are already covered by seed replay (Layer 2a). Scope this only if fixtures turn out to be too coarse-grained to localize a bug.

### Layer 4 — Live object proxying — explicitly out of scope

Running the unmodified `.tests.js` with PHP objects substituted in live (proxying observers, iterators, and internal store access across the process boundary). Documented here so it is a **deliberate non-goal**, not an oversight: the cost is a full remote-object protocol for yjs's internals, and the test comparisons are themselves JS-coupled (`instanceof`, private fields). Layers 1–3 deliver the guarantee without it.

## The three phases

This refines your proposed 1) harness → 2) stub → 3) implement, with one addition: a **Phase 0** for the binary foundation, because it's the riskiest piece and every test depends on it.

### Phase 0 — Foundation: lib0 primitives + harness skeleton

Port the load-bearing primitives and stand up the test runner so *anything* can execute.

- `Lib0\Encoding`, `Lib0\Decoding`, `Lib0\Binary` (bit masks), `Lib0\Buffer` (base64/copy), and the byte-buffer type.
- Small utility shims: `Math`, `Arr`, `Mp` (map), `St` (set), `Obj`, `Func`, `Iterator`, `Error`, `Observable` (event emitter), `Prng`, `Random`, `Time`, `Str`.
- `tests/Support/` assertion module + PHPUnit wiring; `composer test` runs green on an empty suite.
- **Exit criterion:** a standalone test proves `Lib0\Encoding` round-trips and matches **captured JS bytes** for `writeVarUint`, `writeVarInt`, `writeVarString`, `writeAny`, `writeFloat*`. If this isn't byte-perfect, nothing downstream can be.

### Phase 1 — Stub the entire public API

Generate PHP skeletons for every export in [yjs/src/index.js](yjs/src/index.js) — all classes (`Doc`, `Transaction`, `Item`, `AbstractStruct`, `GC`, `Skip`, every `Content*`, `AbstractType`, `YArray/YMap/YText/YXml*`, every `*Event`, `ID`, `Snapshot`, `RelativePosition`, `AbstractConnector`, `UndoManager`, `PermanentUserData`, `UpdateEncoder/Decoder` V1+V2) and all free functions (`applyUpdate`, `encodeStateAsUpdate`, `encodeStateVector`, `mergeUpdates`, `diffUpdate`, `snapshot`, `createRelativePositionFromTypeIndex`, …) — each throwing `NotImplemented`.

Translate the test files against these stubs. **Result: the whole suite runs and fails** — the red baseline that makes red→green meaningful.

### Phase 2 — Implement bottom-up, dependency-ordered

Implement against the dependency graph (NOT the test-file order), turning tests green in waves. V1 encoding throughout; **V2 deferred** to a later milestone (the sync protocol the convergence tests use is V1-only anyway — `init()` randomly picks V2 but falls back to V1).

```
ID, createID, compareIDs
  └─ UpdateEncoderV1 / UpdateDecoderV1     (struct-aware wrappers over Lib0\Encoding)
       └─ StructStore
            └─ DeleteSet
                 └─ AbstractStruct → GC, Skip
                      └─ Content* (String, JSON, Any, Binary, Embed, Format, Deleted, Type, Doc)
                           └─ Item                          (816 lines — the keystone)
                                └─ AbstractType + EventHandler + YEvent
                                     └─ Transaction
                                          └─ Doc
                                               └─ Utils\Encoding            (readStructs, applyUpdate,
                                                                              encodeStateAsUpdate, encodeStateVector,
                                                                              integrateStructs, pending handling)
                                                    ├─ YArray, YMap, YText, YXmlFragment/Element/Text/Hook
                                                    ├─ Utils\Updates        (mergeUpdates, diffUpdate,
                                                    │                         parseUpdateMeta, obfuscate, V1↔V2 convert)
                                                    ├─ Snapshot, RelativePosition (full), PermanentUserData
                                                    └─ UndoManager
                                                         └─ y-protocols/sync (port the sync subset the
                                                                              convergence harness needs)
```

Test files come green roughly in this order: `encoding` + `doc` → `y-array` → `y-map` → `y-text` → `y-xml` → `updates` → `snapshot` → `relativePositions` → `undo-redo` → `compatibility`.

## PHP-specific porting hazards (where byte-compat will bite)

These are the concrete traps. Each one is a thing the conformance layer (Layer 2) is designed to catch.

1. **`Uint8Array` → PHP binary string.** Pick one representation and wrap it. PHP strings are byte arrays already, which is convenient, but slicing/concat semantics and mutability differ. A thin `Buffer` class keeps this from leaking everywhere.

2. **No `>>>` / unsigned 32-bit.** Variable-length integer encoding relies on unsigned right shift and 32-bit wraparound. PHP `>>` is arithmetic and ints are 64-bit. We must mask with `& 0xFFFFFFFF` and emulate `>>>`. Client IDs and clocks are unsigned 32-bit; getting this wrong corrupts every update.

3. **`writeAny` number-type dispatch must be replicated exactly** (see [lib0/encoding.js](yjs/node_modules/lib0/encoding.js) `writeAny`): integer with `abs(n) <= 2^31-1` → type 125 varint; else float32 if it round-trips → type 124; else float64 → type 123. PHP must make the *same* int-vs-float decision so `ContentAny` bytes match. PHP's int/float distinction mostly aligns, but `1.0` vs `1` and the float32 round-trip check need deliberate handling.

4. **`undefined` (type 127) vs `null` (type 126).** JS distinguishes them; PHP has only `null`. Decoding must map 127 to a sentinel (or `null`) and we must decide what PHP value re-encodes to which type. Rare in practice (Yjs almost never stores `undefined`), but the decoder must not choke on it.

5. **`bigint` (type 122).** PHP ints are 64-bit; values beyond need GMP/BCMath or a string carrier. Edge case — defer, but don't let the decoder crash on it.

6. **Float endianness.** `writeFloat32/64` use a big-endian `DataView` (`littleEndian = false`). PHP `pack()` must use the matching big-endian format codes (`G`/`E` or manual byte order), not native order.

7. **UTF-8 string length.** `writeVarString` writes the byte length then UTF-8 bytes. PHP strings are already byte sequences — use `strlen` (bytes), never `mb_strlen` (chars), for the length prefix.

8. **Object key ordering.** `writeAny` for objects iterates `Object.keys` in insertion order. PHP associative arrays preserve insertion order too, so this aligns — but any place we build a map from sorted/other order must match JS exactly or object bytes diverge.

9. **`instanceof` constructor checks.** Yjs leans on `struct instanceof GC` etc. PHP `instanceof` works the same; the risk is only if we collapse class hierarchies. Keep the struct/content class shapes 1:1 with JS.

10. **Number precision in JSON.** `toJSON()` comparisons and float round-trips must match JS `Number` (IEEE-754 double) behavior. PHP floats are also doubles, so this aligns, but JSON encoding of e.g. `1.0` differs (`1` vs `1.0`) — compare structurally, not as JSON strings.

11. **Performance.** PHP is slower than V8; the fuzz suite's `--repetition-time` budget should be scaled down (fewer iterations per run) and the heavy runs pushed to CI. Item integration is hot — the search-marker optimization in `AbstractType`/`Item` matters.

## Milestones & definition of done

- **M0 — Foundation green.** `Lib0\Encoding`/`Decoding` byte-match captured JS output for all primitive writers; harness runs. *DoD: encoding primitives conformance test passes.*
- **M1 — Red baseline.** Full API stubbed, all 208 tests translated and failing for the right reason (`NotImplemented`, not parse errors).
- **M2 — Structured data.** `Doc` + `YMap` + `YArray` + `applyUpdate`/`encodeStateAsUpdate`/`encodeStateVector`. *DoD: `doc`, `y-map`, `y-array` unit + fuzz tests green; fixtures byte-match JS.*
- **M3 — Rich text.** `YText` (formatting, deltas, embeds). *DoD: `y-text` green incl. convergence.*
- **M4 — XML.** `YXmlFragment/Element/Text/Hook`. *DoD: `y-xml` green.*
- **M5 — Updates & history.** `mergeUpdates`, `diffUpdate`, `Snapshot`, `RelativePosition`, `UndoManager`. *DoD: `updates`, `snapshot`, `relativePositions`, `undo-redo` green.*
- **M6 — Interop hardening.** Cross-decode real browser-generated updates; `compatibility` tests green; build the Layer-3 op-log bridge only if Layer-2 fixtures prove too coarse.
- **M7 — V2 encoding** (deferred). `UpdateEncoderV2/DecoderV2`, V1↔V2 convert. Only needed if a consumer requires the V2 wire format.

**Global definition of done:** all 208 translated tests pass, the fuzz suite converges across users for many seeds matching JS, and every fixture byte-matches real yjs output in both directions.

## Deferred / open questions

- **V2 encoding** (M7) — confirm whether any target client actually emits V2; if not, it stays deferred.
- **`y-protocols`** — we only need the **sync** subset for the convergence harness; **awareness** is out of scope unless the WP integration needs presence.
- **Subdocuments** (`ContentDoc`) and **`PermanentUserData`** — supported in the port but low priority; sequence after M5.
- **WordPress integration surface** — storage (where updates persist: postmeta? custom table?), the HTTP/WebSocket sync endpoint, and locking/merge-on-write — these live in a *separate* plan; this document covers only the library port.
- **Packaging** — Composer package now; whether to also ship as a WP plugin/mu-plugin is a downstream decision.
