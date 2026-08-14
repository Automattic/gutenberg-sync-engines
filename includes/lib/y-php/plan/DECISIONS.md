# Decisions Log

A living record of cross-cutting choices made while porting. **Read this before writing code; append to it when you make a decision a later milestone could depend on.** This is the glue that keeps independently-run agents byte-compatible — if another agent would otherwise have to guess what you did, it belongs here.

## What to log

- Shared primitives and their names (the byte buffer, `>>>` helper, float pack codes).
- Representation choices (how `undefined` decodes, how binary is carried).
- Any **deviation from the JS source**, with the reason.
- Tests skipped or adapted, with the reason.
- Anything you spent more than a few minutes deciding.

Do **not** log routine ports that follow the JS source faithfully — only the choices.

## Entry format

```
### DEC-NNNN — <short title>
- **Milestone:** M_
- **Status:** accepted | superseded by DEC-MMMM
- **Decision:** what was chosen, concretely (name the class/method/constant).
- **Why:** the reasoning, especially any JS behavior being matched.
- **Affects:** which later milestones or modules must follow this.
```

Use the next free `DEC-NNNN` number. Never edit a prior entry's decision; if it changes, add a new entry and mark the old one `superseded by DEC-MMMM`.

---

## Locked at planning time

### DEC-0001 — Target platform and packaging
- **Milestone:** planning
- **Status:** accepted
- **Decision:** PHP 7.4+ floor, Composer package, PSR-4 namespace `Yjs\`. lib0 ported under `Yjs\Lib0`.
- **Why:** PHP 7.4 is the floor for the WordPress versions we deploy on; Composer + PSR-4 keeps it consumable as a standard library.
- **Affects:** all milestones.

### DEC-0002 — Byte-exact parity is the contract
- **Milestone:** planning
- **Status:** accepted
- **Decision:** PHP encoder output must be byte-identical to JS for the same logical operation. Mirror the JS source rather than refactoring it.
- **Why:** Full wire-interop with live JS clients is the project's reason to exist.
- **Affects:** all milestones, especially every encoder/decoder.

### DEC-0003 — V2 encoding deferred
- **Milestone:** planning
- **Status:** accepted
- **Decision:** Implement V1 update encoding throughout; defer `UpdateEncoderV2`/`DecoderV2` and V1↔V2 conversion to M7. The sync protocol used by the convergence harness is V1-only.
- **Why:** Reduces scope of the critical path; V2 only matters if a consumer requires that wire format.
- **Affects:** M2–M6 implement V1 only; M7 revisits.

### DEC-0004 — PHP 7.4 language baseline
- **Milestone:** planning
- **Status:** accepted
- **Decision:** Write to the 7.4 feature set: no enums (use `final class` + `const`), no `readonly`, no constructor promotion, no native union types (use PHPDoc), no `match`/named-args/nullsafe/first-class-callable/`mixed`. Typed properties and arrow functions are allowed.
- **Why:** Keeps the library runnable on the PHP 7.4 floor set in DEC-0001 ([WP/PHP compatibility table](https://make.wordpress.org/core/handbook/references/php-compatibility-and-wordpress-versions/)).
- **Affects:** every milestone — all produced PHP. CONTEXT "PHP language baseline" governs the specifics.

### DEC-0005 — WPCS minus naming sniffs; camelCase API; PSR-4 filenames
- **Milestone:** planning
- **Status:** accepted
- **Decision:** Produced PHP must pass **WordPress Coding Standards** via PHPCS, but exclude `WordPress.NamingConventions.ValidFunctionName`, `…ValidVariableName`, and `WordPress.Files.FileName`. Keep Yjs's **camelCase** method/property names and **PSR-4** filenames (`Item.php`). All other WPCS rules (Yoda, spacing, security) apply. Config lives in `phpcs.xml.dist`; gate with `composer lint`.
- **Why:** WPCS naming requires snake_case methods and `class-*.php` files, which collides with the prime directive of mirroring the Yjs API 1:1 (DEC-0002). Keeping camelCase preserves fidelity to the JS source and API familiarity for Yjs users while still getting WPCS's formatting/security value.
- **Affects:** every milestone; the M0 tooling setup; the public API surface.

<!-- New entries below this line -->

### DEC-0006 — Build root is `y-php/`
- **Milestone:** M0
- **Status:** accepted
- **Decision:** Treat `y-php/` as the Composer package root. Implementation files live under `y-php/src`, tests under `y-php/tests`, tools under `y-php/tools`; run `composer test`, `composer lint`, `composer lint:fix`, and `node tools/gen-fixtures.mjs` from `y-php/`.
- **Why:** The parent workspace contains sibling JS repositories used as source references. Running patches or build commands from the parent can accidentally create parent-level `src/`, `tests/`, or `tools/` directories outside the PHP package.
- **Affects:** all milestones and all local build/test instructions.

### DEC-0007 — Buffer carrier is `Yjs\Lib0\Buffer`
- **Milestone:** M0
- **Status:** accepted
- **Decision:** Use `Yjs\Lib0\Buffer` as the one wrapped `Uint8Array` representation. It stores bytes as a PHP binary `string` and exposes `fromBinaryString()`, `fromByteArray()`, `toBinaryString()`, `toByteArray()`, `byteLength()`, `get()`, `set()`, `slice()`, base64/base64url/hex helpers, and `copyUint8Array()`.
- **Why:** The wire format is byte-oriented, and PHP strings are the most direct binary carrier. The wrapper keeps later code from mixing raw PHP strings and logical text accidentally.
- **Affects:** all update encoders/decoders, content binary payloads, fixture readers, sync/update tests.

### DEC-0008 — JavaScript 32-bit helpers live in `Yjs\Lib0\Binary`
- **Milestone:** M0
- **Status:** accepted
- **Decision:** Implement JavaScript-style bit coercion in `Yjs\Lib0\Binary`: `toUint32()`, `toInt32()`, `unsignedRightShift()` for `>>>`, plus `shiftLeft32()`, `or32()`, and `xor32()` for PRNG and future clock/client-id logic.
- **Why:** PHP integers are 64-bit and `>>` is arithmetic. Yjs/lib0 relies on JavaScript 32-bit bitwise coercion and unsigned right shift for deterministic encoding and PRNG output.
- **Affects:** PRNG, update codecs, IDs/clocks, any code porting JS bitwise operators.

### DEC-0009 — `undefined` uses a singleton carrier
- **Milestone:** M0
- **Status:** accepted
- **Decision:** Decode lib0 `writeAny` tag `127` to `Yjs\Lib0\UndefinedValue::getInstance()`. `Encoding::writeAny()` recognizes that singleton and writes tag `127`; PHP `null` remains tag `126`.
- **Why:** PHP has no native `undefined`, but lib0 distinguishes `undefined` from `null` on the wire. A singleton preserves the distinction without overloading `null`.
- **Affects:** `writeAny`/`readAny`, JSON-like payloads, future map/text attribute handling.

### DEC-0010 — BigInt64 uses a decimal-string carrier
- **Milestone:** M0
- **Status:** accepted
- **Decision:** Represent lib0 bigint values with `Yjs\Lib0\BigInt64`, storing a signed decimal string. `Encoding::writeBigInt64()` accepts `BigInt64`, `int`, or decimal `string`; `Decoding::readBigInt64()` returns `BigInt64`. Conversion to bytes is big-endian signed 64-bit two's-complement.
- **Why:** PHP 7.4 integers are platform 64-bit here, but a decimal-string carrier avoids crashing or losing the exact edge values when decoding `-9223372036854775808` and keeps the representation explicit for later agents.
- **Affects:** `writeAny`/`readAny`, any future binary fixture or awareness payload containing bigint.

### DEC-0011 — Floats use big-endian pack codes
- **Milestone:** M0
- **Status:** accepted
- **Decision:** Use `pack('G', $num)` / `unpack('G', ...)` for `writeFloat32`/`readFloat32` and `pack('E', $num)` / `unpack('E', ...)` for `writeFloat64`/`readFloat64`.
- **Why:** lib0 writes floats with `DataView` littleEndian `false`, i.e. network/big-endian byte order. Native-order PHP pack codes would break byte parity across platforms.
- **Affects:** all lib0 primitive encoding, `writeAny` number dispatch, fixtures.

### DEC-0012 — PRNG class names mirror lib0
- **Milestone:** M0
- **Status:** accepted
- **Decision:** Port lib0 PRNG as `Yjs\Lib0\Xorshift32`, `Yjs\Lib0\Xoroshiro128plus`, and static helper module `Yjs\Lib0\Prng`. `Prng::create()` returns `Xoroshiro128plus`.
- **Why:** Later fuzz/convergence tests depend on fixed seeds producing the same scenario as JS. Keeping the lib0 class names makes source comparison and fixture diagnosis direct.
- **Affects:** all fuzz tests and any generated randomized test data in later milestones.

### DEC-0013 — M0 PHPCS gate keeps WPCS but excludes doc/name noise
- **Milestone:** M0
- **Status:** accepted
- **Decision:** `phpcs.xml.dist` uses `WordPress` + `PHPCompatibilityWP` with DEC-0005 exclusions, and additionally excludes `Generic.Commenting.DocComment.MissingShort`, `Squiz.Commenting.FunctionComment.Missing`, `Squiz.Commenting.FunctionCommentThrowTag.Missing`, and `Universal.NamingConventions.NoReservedKeywordParameterNames`. The Composer package is `phpcompatibility/phpcompatibility-wp`.
- **Why:** The first M0 lint run showed docblock boilerplate and reserved JS parameter names dominating the signal. Excluding those keeps the lint gate focused on formatting, compatibility, and security while preserving camelCase/lib0 naming. Packagist exposes the PHPCompatibilityWP ruleset package as `phpcompatibility/phpcompatibility-wp`; the unhyphenated name is not installable.
- **Affects:** all PHP code and future Composer installs/lint runs.

### DEC-0014 — Public API stubs use module namespaces plus top-level aliases
- **Milestone:** M1
- **Status:** accepted
- **Decision:** Stub classes live in the planned module namespaces (`Yjs\Utils\Doc`, `Yjs\Types\YArray`, `Yjs\Structs\Item`, etc.) and `src/aliases.php` registers top-level aliases such as `Yjs\Doc`, `Yjs\YArray`, and `Yjs\ContentString`. Free-function exports live as namespace functions in `src/functions.php`; `Yjs\Yjs` is a static facade that forwards to those functions.
- **Why:** The directory layout from CONTEXT stays PSR-4-compliant while top-level aliases keep the public API close to the JS export names. PHP cannot safely mirror every JS alias literally (notably `Array`), so later milestones should use the `Y*` class names already used internally by Yjs.
- **Affects:** all later class implementations, public API docs, translated tests, and any consumer code.

### DEC-0015 — M1 test translation is a named red baseline
- **Milestone:** M1
- **Status:** accepted
- **Decision:** Each exported `yjs/tests/*.tests.js` test is represented by one PHPUnit method with the same test name and source reference. Until behavior milestones replace the bodies, the translated methods enter the public `Doc` stub and fail with `Yjs\NotImplemented`. `testFailsObjectManipulationInDevMode` is explicitly skipped because it relies on JS dev-mode `Object.freeze`, which has no PHP array/object equivalent.
- **Why:** M1's exit criterion is a suite that compiles and fails for the right reason, not behavioral green tests. Keeping the method names/source references creates stable slots for later agents to port each body without rediscovering the JS test inventory.
- **Affects:** all later milestone test ports; the skipped dev-mode freeze test should remain skipped unless a PHP-specific invariant is defined.

### DEC-0016 — WPCS underscore-method sniff is excluded for JS parity
- **Milestone:** M1
- **Status:** accepted
- **Decision:** `phpcs.xml.dist` now excludes `PSR2.Methods.MethodDeclaration.Underscore` in addition to the M0 naming exclusions.
- **Why:** Yjs exposes and internally relies on underscore-prefixed methods such as `_integrate`, `_copy`, `_write`, `_callObserver`, and the test helper `_receive`. Renaming them for style would make source comparison harder and drift from the JS API shape.
- **Affects:** all type/struct ports and translated test helper code that mirrors underscore-prefixed JS methods.

### DEC-0017 — Yjs scenario fixtures use deterministic client IDs
- **Milestone:** M1
- **Status:** accepted
- **Decision:** `tools/gen-fixtures.mjs` writes `tests/fixtures/yjs-scenarios.json` with `json`, `updateHex`, `stateVectorHex`, and `snapshotHex` captured from real `yjs/src/index.js` scenarios. Each scenario pins `doc.clientID` to a deterministic integer before applying operations.
- **Why:** Real Yjs `Doc` client IDs are random by default; pinning them keeps regenerated fixtures byte-stable while preserving the exact JS encoder output for representative array, map, and text operations.
- **Affects:** future fixture-driven byte-parity tests and any new generated Yjs scenario fixtures.

### DEC-0018 — V1 update codecs keep the JS base-class split
- **Milestone:** M2.1
- **Status:** accepted
- **Decision:** Add `Yjs\Utils\DSEncoderV1` and `Yjs\Utils\DSDecoderV1` as concrete base classes with public `restEncoder`/`restDecoder` properties. `Yjs\Utils\UpdateEncoderV1` and `Yjs\Utils\UpdateDecoderV1` extend those classes and write/read directly through the already byte-verified lib0 primitives. `UpdateEncoderV2`/`UpdateDecoderV2` remain M1 stubs.
- **Why:** JS keeps delete-set and update codecs in a class hierarchy, and later modules perform constructor/class-shape-sensitive work. Preserving the V1 base-class shape now avoids changing delete-set, snapshot, and update code later.
- **Affects:** M2.2 delete sets, M2.3 structs/content, M2.4 update integration, M7 V2 codecs.

### DEC-0019 — ID helpers are namespaced functions over a value object
- **Milestone:** M2.1
- **Status:** accepted
- **Decision:** `Yjs\Utils\ID` is a simple value object with public integer `client` and `clock` properties. The JS helper functions live as `Yjs\createID()`, `Yjs\compareIDs()`, `Yjs\writeID()`, `Yjs\readID()`, and `Yjs\findRootTypeKey()` in `src/functions.php`; the public `Yjs\Yjs` facade forwards only the helpers exported by `yjs/src/index.js`.
- **Why:** JS modules import these helpers from `ID.js`, while public `index.js` only re-exports `createID`, `compareIDs`, and `findRootTypeKey`. Keeping `writeID`/`readID` as namespace helpers gives later internal ports the same primitive without expanding the facade surface beyond JS.
- **Affects:** relative positions, snapshots, updates, structs, and any code comparing or serializing IDs.

### DEC-0020 — V1 JSON codec mirrors `JSON.stringify` string bytes
- **Milestone:** M2.1
- **Status:** accepted
- **Decision:** `UpdateEncoderV1::writeJSON()` serializes with JS-compatible JSON string bytes: `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`, top-level `UndefinedValue` writes the string `undefined`, undefined array entries become `null`, undefined object properties are omitted, non-finite floats become `null`, negative zero becomes `0`, and `BigInt64` throws. `UpdateDecoderV1::readJSON()` decodes to PHP `stdClass`/arrays/scalars via `json_decode()` and throws on parse errors.
- **Why:** V1 stores JSON by first applying `JSON.stringify()` and then `writeVarString()`. PHP's default `json_encode()` escapes slashes and Unicode differently from JS, which would change update bytes for ordinary embeds.
- **Affects:** ContentEmbed, ContentJSON-adjacent logic, V1 update fixtures, and any later code using `writeJSON`/`readJSON`.

### DEC-0021 — StructStore and DeleteSet maps are ordered PHP arrays
- **Milestone:** M2.2
- **Status:** accepted
- **Decision:** Represent `StructStore::$clients` and `DeleteSet::$clients` as insertion-ordered PHP arrays keyed by integer client id. `getStateVector()` iterates store clients in insertion order, while `writeDeleteSet()` builds a temporary entry list and sorts clients descending by id before writing, matching `Array.from(ds.clients.entries()).sort((a, b) => b[0] - a[0])`.
- **Why:** JS `Map` preserves insertion order, but delete-set encoding deliberately overrides that order for deterministic bytes. PHP arrays preserve insertion order for integer keys and keep the implementation byte-compatible without adding a separate map abstraction.
- **Affects:** state-vector encoding, update encoding, delete-set merge/equality, and any later code that iterates store or delete-set clients.

### DEC-0022 — M2.2 introduces only the minimal struct item surface needed for store splitting
- **Milestone:** M2.2
- **Status:** accepted
- **Decision:** `AbstractStruct`, `Item`, `GC`, and `Skip` now expose real `id`, `length`, and JS-style computed properties needed by `StructStore`/`DeleteSet`; `Yjs\splitItem()` mirrors the JS split logic and expects item content objects to provide `getLength()`, `isCountable()`, and `splice()`. Full item integration, content encoding, and type-parent behavior remain for the later struct/content milestones.
- **Why:** `getItemCleanStart()`, `getItemCleanEnd()`, and `iterateStructs()` cannot be byte- or behavior-tested without actual item splitting, but porting all of `Item.js` and every content class would exceed M2.2. The minimal surface preserves constructor/class shape while leaving unimplemented behavior explicit.
- **Affects:** M2.3 structs/content, M2.4 update integration, delete application, GC, and any test helper that constructs items directly.

### DEC-0023 — Delete-set codec helpers use object parameters for future V2 parity
- **Milestone:** M2.2
- **Status:** accepted
- **Decision:** `Yjs\writeDeleteSet()` and `Yjs\readDeleteSet()` accept native `object` parameters instead of V1-only class typehints. They still exercise `DSEncoderV1`/`DSDecoderV1` today, but the call shape matches JS's `DSEncoderV1 | DSEncoderV2` and `DSDecoderV1 | DSDecoderV2` helpers.
- **Why:** PHP 7.4 has no native union types, and `UpdateEncoderV2`/`UpdateDecoderV2` are deliberately deferred stubs per DEC-0003/DEC-0018. A V1-only typehint would force M7 to change the public/internal helper signature just to add V2 support.
- **Affects:** M7 V2 codecs, snapshot/delete-set encoding, update integration, and any code calling delete-set wire helpers.

### DEC-0024 — ContentString stores UTF-8 while counting UTF-16 code units
- **Milestone:** M2.3
- **Status:** accepted
- **Decision:** `Yjs\Structs\ContentString::$str` remains a PHP UTF-8 string, but `getLength()`, `write($encoder, $offset)`, and `splice($offset)` interpret offsets as JavaScript UTF-16 code units. Splitting inside a surrogate pair yields U+FFFD replacement characters on each side, matching Yjs's invalid-surrogate guard. PHP cannot naturally expose JS's lone surrogate strings from `str.split('')`, so `getContent()` returns UTF-8 string slices with U+FFFD for partial code points.
- **Why:** Yjs uses `String.length`, `slice()`, and a surrogate-pair replacement guard. Update bytes depend on UTF-16 offsets, while PHP strings are byte/UTF-8 carriers.
- **Affects:** Item splitting, text insertion/deletion, snapshot/list materialization, and any future code using `ContentString::getContent()`.

### DEC-0025 — Content readers are namespace helpers; type/doc shells are byte-surface only
- **Milestone:** M2.3
- **Status:** accepted
- **Decision:** Add JS module helper equivalents as namespace functions in `src/functions.php`: `readItemContent()`, all `readContent*()`, and `readYArray()`/`readYMap()`/`readYText()`/`readYXml*()`. The concrete type classes and `Doc` now expose only the minimal constructor, `_copy()`, `_integrate()`, `_write()`, and field surface needed by `ContentType`/`ContentDoc` byte encode/decode. Full list/map/text/xml editing and transaction behavior remains M2.4+ work.
- **Why:** DEC-0014 already chose namespace functions for JS module helpers. `ContentType` and `ContentDoc` need real read/write/copy behavior in M2.3, but their live integration depends on the type/doc runtime scheduled for M2.4.
- **Affects:** M2.4 update integration, type implementations, item decoding, and any code reading content refs.

### DEC-0026 — ContentDoc options use stdClass object semantics
- **Milestone:** M2.3
- **Status:** accepted
- **Decision:** `Yjs\Structs\ContentDoc::$opts` is a `stdClass`, not an array, so `UpdateEncoderV1::writeAny()` emits the JS object tag for `{}` and preserves object key insertion order. `ContentDoc::createDocFromOpts($guid, $opts)` normalizes decoded options and sets `shouldLoad` to `opts.shouldLoad || opts.autoLoad || false`, mirroring JS.
- **Why:** PHP empty arrays encode as lib0 arrays, while JS subdoc options are plain objects. The empty-options case must encode as `{}` on the wire.
- **Affects:** Subdocument encoding, future subdoc lifecycle work, fixture generation, and any code constructing `ContentDoc` from decoded updates.

### DEC-0027 — WPCS underscore-property sniff is excluded for JS parity
- **Milestone:** M2.3
- **Status:** accepted
- **Decision:** `phpcs.xml.dist` now excludes `PSR2.Classes.PropertyDeclaration.Underscore` in addition to the existing method-underscore exclusion.
- **Why:** Yjs's core type/doc state is carried in underscore-prefixed properties such as `_item`, `_map`, `_start`, `_length`, `_searchMarker`, `_prelimContent`, and `_transactionCleanups`. Renaming them would obscure source parity and make later ports harder to compare.
- **Affects:** All type/doc ports, transaction/runtime work, and WPCS lint behavior.

### DEC-0028 — Transaction object-keyed maps use SplObjectStorage
- **Milestone:** M2.4
- **Status:** accepted
- **Decision:** `Yjs\Utils\Transaction::$changed`, `$changedParentTypes`, `$subdocsAdded`, `$subdocsRemoved`, and `$subdocsLoaded` use `SplObjectStorage` where JS uses `Map` or `Set` keyed by type/doc objects. The attached payloads remain PHP arrays for the per-type changed keys and parent-subscriptions lists.
- **Why:** PHP arrays cannot key by object identity, while the JS runtime relies on object identity for observer dispatch, changed-type cleanup, and subdoc bookkeeping.
- **Affects:** Event handling, observer/deep-observer ports, transaction cleanup, subdoc lifecycle work, and later type milestones.

### DEC-0029 — M2.4 update V2 entry points remain V1-backed
- **Milestone:** M2.4
- **Status:** accepted
- **Decision:** `applyUpdateV2()`, `readUpdateV2()`, `encodeStateAsUpdateV2()`, and `encodeStateVectorV2()` are implemented for the M2.4 public call surface but continue to use the V1 encoder/decoder classes by default. Pending struct/delete-set writes also use V1 buffers in this milestone.
- **Why:** DEC-0003 and DEC-0018 defer real V2 codecs to M7, while M2.4 needs the public update helpers to exist for integration and sync code without starting the V2 port early.
- **Affects:** M7 V2 codecs, pending-update handling, sync/update helpers, and any tests that call V2-named helpers before M7.

### DEC-0030 — M2.4 Doc tests are asserted at the milestone boundary
- **Milestone:** M2.4
- **Status:** accepted
- **Decision:** `tests/Unit/DocTest.php` now contains real assertions for the M2.4 runtime, fixture ingestion/re-emission, state-vector parity, load/sync events, and root-type JSON behavior. The after-transaction recursion test uses 256 XML insertions instead of the JS suite's 15000, and the origin, full subdoc lifecycle/autoload, subdoc reload, subdoc load, and subdoc undo tests are explicit PHPUnit skips.
- **Why:** The placeholder translated tests were risky because they asserted nothing. The adapted/skipped cases depend on later Text snapshot/toDelta cleanup, full subdoc provider semantics, UndoManager, and XML behavior outside M2.4 scope.
- **Affects:** Later Text/XML/Undo/subdoc milestones should unskip or restore the full JS cases when their underlying behavior is ported.

### DEC-0031 — Minimal public type wrappers delegate to generic M2.4 helpers
- **Milestone:** M2.4
- **Status:** accepted
- **Decision:** `YArray`, `YMap`, `YText`, and XML fragment/text/element classes expose only the minimal public methods needed by Doc/update integration and current tests, routing list and map behavior through the `typeList*` and `typeMap*` namespace helpers.
- **Why:** M2.4 needs integrated root types that can absorb, materialize, and re-emit updates, but the complete Array/Map/Text/XML APIs belong to the later type milestones.
- **Affects:** M2.5-M2.7 type implementations, observer event details, list/map search marker behavior, and public type API completion.

### DEC-0032 — Sync protocol implementation is the V1 subset
- **Milestone:** M2.4
- **Status:** accepted
- **Decision:** `Yjs\Protocols\Sync` implements the V1 sync message flow (`writeSyncStep1`, `writeSyncStep2`, `readSyncStep1`, `readSyncStep2`, `writeUpdate`, and `readSyncMessage`) using lib0 varUint message wrappers plus `encodeStateVector()`, `encodeStateAsUpdate()`, and `applyUpdate()`.
- **Why:** The milestone requires core update exchange and convergence, while sync V2 and awareness/provider behavior are outside the current scope.
- **Affects:** Future sync/provider work, M7 V2 sync behavior, and any convergence harness that builds on the protocol helpers.

### DEC-0033 — Generic event added/deleted sets use SplObjectStorage
- **Milestone:** M2.5
- **Status:** accepted
- **Decision:** `Yjs\Utils\YEvent::changes` now mirrors the JS generic list/map change computation. `changes['added']` and `changes['deleted']` are `SplObjectStorage` identity sets of `Item` objects, `changes['delta']` is an ordered PHP array of `insert`/`delete`/`retain` op arrays, and `changes['keys']` remains an associative array keyed by map key.
- **Why:** JS exposes `Set<Item>` for added/deleted and computes deltas from item identity during observer cleanup. PHP arrays cannot represent object identity sets safely, while `SplObjectStorage` already matches the transaction map/set choice from DEC-0028.
- **Affects:** YArray/YMap/XML observer tests, future event consumers, PermanentUserData, UndoManager, and any code inspecting `YEvent::changes`.

### DEC-0034 — TestConnector is a V1 in-memory sync harness
- **Milestone:** M2.5
- **Status:** accepted
- **Decision:** `tests/Support/TestConnector` and `TestYInstance` now implement the JS test helper's in-memory message queue using `Yjs\Protocols\Sync` V1 messages. Local `update` events are wrapped with `Sync::writeUpdate()`, queued per remote `TestYInstance` in `SplObjectStorage`, and flushed deterministically with the existing lib0 PRNG.
- **Why:** YArray unit and fuzz tests need real multi-document convergence before later provider/sync work exists. DEC-0032 makes V1 the available sync subset, and V2 remains deferred by DEC-0003.
- **Affects:** All later translated fuzz tests that call `init()`, `compare()`, or `applyRandomTests()`, especially YMap/YText/XML milestones.

### DEC-0035 — M2.5 YArray tests adapt JS production and mergeUpdates checks
- **Milestone:** M2.5
- **Status:** accepted
- **Decision:** `tests/Unit/YArrayTest.php` ports the JS YArray unit and fuzz tests with real assertions. The JS production-only fuzz budgets (`3000`, `5000`, `30000`) remain skipped outside production, and the PHP `compare()` helper validates live synced docs, YArray JSON, state vectors, update bytes, pending-update absence, and iteration parity without invoking `mergeUpdates()`.
- **Why:** The JS suite also skips the largest fuzz budgets unless production mode is enabled. PHP `mergeUpdates()` is still an explicit later update-utility stub, so asserting it in M2.5 would exceed the YArray milestone while adding no YArray-specific coverage.
- **Affects:** Later update utility work should restore the JS helper's `mergeUpdates()` assertion when `mergeUpdates()` is ported; later type milestones can reuse the current live-doc convergence checks.

### DEC-0036 — YArray JS-byte convergence fixtures are operation-log based
- **Milestone:** M2.5
- **Status:** accepted
- **Decision:** `tools/gen-fixtures.mjs` now writes `tests/fixtures/yarray-convergence.json` from real JS Yjs for 10 deterministic seeds. Each case records local per-user YArray operations, including every nested YMap write, then applies each user's final local update to the other users once and stores the converged JSON, state vectors, and update bytes for PHP replay.
- **Why:** This keeps the fixture independent of PHP implementation details while checking multi-user YArray convergence and byte parity against the JS source. Recording every nested map write is necessary because overwritten map values still consume clocks and affect final update bytes.
- **Affects:** YArray conformance, fixture regeneration, and any later test generator that serializes operation logs involving nested shared types.

### DEC-0037 — Transaction observer cleanup uses dynamic `callAll`
- **Milestone:** M2.6
- **Status:** accepted
- **Decision:** `Yjs\Lib0\Func::callAll()` accepts its callback array by reference and re-checks its length after each callback; `callEventHandlerListeners()` delegates to it. `cleanupTransactions()` now queues changed-type observers, then appends deep-observer and `afterTransaction` callbacks during the same `callAll()` run, mirroring `yjs/src/utils/Transaction.js`.
- **Why:** JS guarantees that all observer/deep-observer cleanup callbacks run even when an earlier observer throws, and deep-observer callbacks are appended only after ordinary observers populate `changedParentTypes`. PHP needed the same dynamic queue behavior for YMap observer-exception parity.
- **Affects:** All observer dispatch for YArray, YMap, YText, XML, UndoManager, and future event consumers.

### DEC-0038 — YMap public surface uses ordered PHP arrays for iteration-facing sets
- **Milestone:** M2.6
- **Status:** accepted
- **Decision:** `Yjs\Types\YMap::$_prelimContent` remains an insertion-ordered PHP associative array. The constructor accepts both JS-style iterable `[key, value]` pairs and PHP associative arrays, preserving overwrite order. `keys()`, `values()`, `entries()`, `foreach`, `IteratorAggregate`, `toJSON()`, and magic `size` expose only live entries in `_map` order; `YMapEvent::$keysChanged` remains the transaction subs array (`array<int,string|null>`) rather than introducing a custom string Set class.
- **Why:** PHP arrays preserve the insertion order Yjs relies on for map iteration and JSON/object encoding. Supporting pair iterables is required by `new Y.Map(entries)` parity, while accepting associative arrays keeps PHP callers from needing a wrapper for the same ordered data. A native string-set abstraction would add surface area without changing wire bytes.
- **Affects:** YMap consumers, observer tests, JSON comparison, future XML attribute maps, and any code comparing changed map keys.

### DEC-0039 — M2.6 YMap tests and fixtures follow the M2.5 operation-log pattern
- **Milestone:** M2.6
- **Status:** accepted
- **Decision:** `tests/Unit/YMapTest.php` now ports the YMap unit and fuzz tests with real assertions. The JS production-only fuzz budgets (`5000`, `10000`, `100000`) remain skipped outside production. `testMapEventError` is adapted to first create an event and then assert post-transaction change computation fails; `event.value`/`event.name` are asserted as PHP `null` because Yjs v13.6.31's `YMapEvent` source does not define those properties. `tools/gen-fixtures.mjs` writes `tests/fixtures/ymap-convergence.json` as deterministic JS operation logs for 10 seeds, and `tests/Conformance/YMapConvergenceTest.php` replays them against PHP JSON, state-vector, and update bytes.
- **Why:** The operation-log fixture style keeps byte-parity assertions independent from PHP internals and captures nested type writes whose clocks affect updates. The small unit-test adaptations reflect PHP's lack of JS `undefined` property access and the actual source shape of `YMapEvent`.
- **Affects:** YMap conformance, future fixture regeneration, later event API work, and later milestones reusing `applyRandomTests()`.

### DEC-0040 — YText rich-text helpers mirror JS as namespace functions plus cursor class
- **Milestone:** M3
- **Status:** accepted
- **Decision:** Port `ItemTextListPosition` as `Yjs\Types\ItemTextListPosition`, and port the JS-local YText helpers as namespace functions in `src/functions.php`: `equalAttrs()`, `findPosition()`, `insertText()`, `formatText()`, `deleteText()`, `cleanupFormattingGap()`, `cleanupContextlessFormattingGap()`, `cleanupYTextFormatting()`, and `cleanupYTextAfterTransaction()`. `YText::$_pending` is an array of closures until integration, matching JS pending operations. Omitted `insert()` attributes are represented by PHP `null`, while an explicit attributes array remains explicit so inherited-format behavior matches JS.
- **Why:** YText formatting item order is byte-sensitive. Keeping the JS cursor/helper split makes source comparison direct and preserves the distinction between omitted attributes (`undefined` in JS) and an explicit empty object.
- **Affects:** YText, YXmlText when it reuses text behavior, observer delta generation, remote formatting cleanup, and any future text-related UndoManager work.

### DEC-0041 — Snapshot value object and V1 snapshot codec are available for YText deltas
- **Milestone:** M3
- **Status:** accepted
- **Decision:** Replace the `Snapshot` stub with `Yjs\Utils\Snapshot` carrying public `DeleteSet $ds` and ordered `array<int,int> $sv`, and implement `createSnapshot()`, `snapshot()`, `emptySnapshot()`, `isVisible()`, `splitSnapshotAffectedStructs()`, `encodeSnapshot()`, `decodeSnapshot()`, and `equalSnapshots()` for the V1 codec path. Full `createDocFromSnapshot()` and snapshot update-containment helpers remain deferred stubs.
- **Why:** `YText::toDelta($snapshot, $prevSnapshot)` imports these primitives in the JS source and the y-text tests rely on ychange deltas. The V1 snapshot bytes are just delete-set plus state-vector encoding, so this could be implemented without starting later snapshot-restore scope.
- **Affects:** YText snapshot deltas, future Snapshot tests, `compare()` parity when snapshot checks are restored, and any later V2 snapshot codec work.

### DEC-0042 — YText fixtures and fuzz tests use PHP-scaled budgets
- **Milestone:** M3
- **Status:** accepted
- **Decision:** `tools/gen-fixtures.mjs` now writes `tests/fixtures/ytext-scenarios.json` and `tests/fixtures/ytext-convergence.json`. The convergence fixture follows the M2.5/M2.6 operation-log pattern for 10 deterministic seeds and covers text insertion, formatting, deletion, embeds, and `applyDelta()`. `tests/Unit/YTextTest.php` replaces the translated red-baseline methods with real assertions; the JS `testRepeatGenerateQuillChanges2Repeat` 1000-repeat stress loop is scaled to 25 repeats in PHP, while byte-level multi-user coverage is pinned by the JS fixture seeds.
- **Why:** PHP is slower than V8 (CONTEXT hazard 11), and the conformance fixture provides stronger byte-parity coverage than an oversized local stress loop. Keeping the method inventory avoids losing traceability to `yjs/tests/y-text.tests.js`.
- **Affects:** YText conformance, fixture regeneration, future fuzz budgets, and later milestones that reuse `tests/Support/compare()` for text deltas.

### DEC-0043 — XML traversal, events, and string coercion mirror JS runtime behavior
- **Milestone:** M4
- **Status:** accepted
- **Decision:** Add `Yjs\Types\YXmlTreeWalker` for `YXmlFragment::createTreeWalker()`, with `next()` returning a JS-style `value`/`done` array and `IteratorAggregate` support for PHP iteration. `YXmlEvent::$attributesChanged` is an associative set (`array<string,bool>`) and `$childListChanged` is derived from `null` parent subscriptions. XML string output uses `Yjs\xmlStringifyValue()` for JS-like attribute/text coercion, and `YXmlHook::toString()` returns `[object Object]`.
- **Why:** XML selectors and `toString()` assertions depend on tree order, sorted attributes, and JS string coercion. Hooks do not define a custom JS `toString()`, so child serialization uses the ordinary object string.
- **Affects:** XML observers, query selectors, XML `toString()` comparisons, YXmlHook consumers, future DOM/server serialization work, and fuzz `compare()` checks.

### DEC-0044 — YXml fixtures use operation logs and materialize empty formatting objects
- **Milestone:** M4
- **Status:** accepted
- **Decision:** `tools/gen-fixtures.mjs` now writes `tests/fixtures/yxml-scenarios.json` and `tests/fixtures/yxml-convergence.json`. The convergence fixture follows the YArray/YMap/YText operation-log pattern for 10 deterministic seeds and covers XML attributes, element/text/hook children, deletes, and XML-text formatting. PHP replay converts empty formatting objects from fixture JSON to `stdClass` before calling `YXmlText::format()`, preserving `{}` bytes instead of PHP `[]` bytes. `tests/Support/compare()` now also asserts the default `xml` root string across synced users.
- **Why:** `ContentFormat` V1 uses `JSON.stringify`; decoded JSON cannot distinguish an empty JS object from an empty PHP list unless replay code restores it. Including XML in `compare()` makes local fuzz catch XML convergence drift just like array/text drift.
- **Affects:** YXml conformance, fixture regeneration, future fuzz tests using `compare()`, and any later replay code involving empty JSON objects in formatting attributes.

### DEC-0045 — M5 lazy update utilities are V1-first structs
- **Milestone:** M5
- **Status:** accepted
- **Decision:** Add `Yjs\Utils\LazyStructReader` and `Yjs\Utils\LazyStructWriter` plus namespace helpers for `mergeUpdates()`, `diffUpdate()`, `encodeStateVectorFromUpdate()`, `parseUpdateMeta()`, `decodeUpdate()`, `logUpdate()`, `obfuscateUpdate()`, and their `*V2` signatures. The implementation uses the V1 encoders/decoders by default and keeps lazy struct iteration in ordered PHP arrays keyed by client id.
- **Why:** `yjs/src/utils/updates.js` implements these utilities without materializing a full `Doc`, and milestone 5 is explicitly V1-only under DEC-0003. Keeping lazy reader/writer classes close to the JS source preserves byte-for-byte merge/diff/state-vector output.
- **Affects:** Update utility consumers, conformance fixtures, offline update processing, later V2 update conversion work, and any future optimization around update parsing.

### DEC-0046 — Snapshot restore uses V1 update encoding until V2 exists
- **Milestone:** M5
- **Status:** superseded by DEC-0055
- **Decision:** Complete snapshot helpers (`typeListToArraySnapshot()`, `typeMapGetSnapshot()`, `typeMapGetAllSnapshot()`, `createDocFromSnapshot()`, `snapshotContainsUpdate()`) on the V1 path. `createDocFromSnapshot()` writes the snapshot restore update with `UpdateEncoderV1`; V2-facing conversion helpers remain V1 passthroughs/stubs until M7 implements real V2 codecs.
- **Why:** The JS source routes snapshot restore through update encoding, but DEC-0003 defers V2. Using V1 now satisfies M5 byte parity for the required snapshot/update tests without inventing a partial V2 format.
- **Affects:** Snapshot consumers, update containment checks, M7 V2 conversion, and any caller expecting V1 update bytes from restored snapshot docs.

### DEC-0047 — Relative position objects preserve JS wire shape, not redundant root names
- **Milestone:** M5
- **Status:** accepted
- **Decision:** `RelativePosition` carries public `?ID $type`, `?string $tname`, `?ID $item`, and `int $assoc`; `AbsolutePosition` carries public `AbstractType $type`, `int $index`, and `int $assoc`. Encoding follows JS precedence: when an item id is encoded, the root type name is not redundantly encoded, so tests compare decoded/encoded stability rather than requiring the original in-memory object to keep `tname`.
- **Why:** JS relative-position encoding omits redundant root-name data when an item id is sufficient. PHP should match the wire bytes even if the original PHP value object had extra derivable fields.
- **Affects:** Relative position APIs, JSON/byte round-trips, tests that compare relative positions, and later cursor/presence integrations.

### DEC-0048 — UndoManager stack state uses PHP identity containers and clears current item before pop events
- **Milestone:** M5
- **Status:** accepted
- **Decision:** Add `Yjs\Utils\UndoManager` and `StackItem`. Undo/redo stacks are ordered PHP arrays of `StackItem`; stack insertions/deletions use `DeleteSet`; stack item metadata is a PHP array; tracked origins are ordered PHP arrays that match by object identity or class-string. `popStackItem()` clears `UndoManager::$currStackItem` before emitting `stack-item-popped`.
- **Why:** PHP lacks JS `Set`/`Map` semantics for mixed scalar/object origins, so arrays plus existing identity containers keep behavior predictable. Clearing `currStackItem` before the event is a deliberate PHP reentrancy guard: listeners may call `undo()`/`redo()` synchronously, and leaving the item set during emission can recurse forever.
- **Affects:** UndoManager consumers, event listeners, tracked-origin matching, future provider/origin integrations, and any later port of JS undo-redo edge cases.

### DEC-0049 — PermanentUserData observes synchronously and stores lookup maps as arrays
- **Milestone:** M5
- **Status:** accepted
- **Decision:** Implement `Yjs\Utils\PermanentUserData` with `clients` as `array<int,string>` and `dss` as `array<string,DeleteSet>`. The JS `setTimeout(..., 0)` delete-set write is adapted to an immediate PHP `afterTransaction` listener that appends the encoded delete set during cleanup when the transaction is local and passes the configured filter.
- **Why:** PHP has no event-loop timeout equivalent in the library runtime. Running immediately after the transaction preserves the durable delete-set mapping needed by sync and avoids requiring callers/tests to pump an async queue.
- **Affects:** PermanentUserData, deleted-id user lookup, user-data sync across docs, EncodingTest coverage, and future async/provider layers that may choose to introduce scheduling.

### DEC-0050 — M5 JS byte fixtures cover update utilities directly
- **Milestone:** M5
- **Status:** accepted
- **Decision:** `tools/gen-fixtures.mjs` writes `tests/fixtures/update-utilities-v1.json` from real JS Yjs. `tests/Conformance/UpdateUtilitiesV1Test.php` asserts `mergeUpdates()`, `diffUpdate()`, `encodeStateVectorFromUpdate()`, `parseUpdateMeta()`, snapshot bytes, and applying merged updates against those fixture bytes.
- **Why:** The milestone requires merge/diff/snapshot/state-vector outputs to byte-match real JS. Direct fixtures catch byte-order and lazy-decoder drift that ordinary document convergence tests can miss.
- **Affects:** Fixture regeneration, update utility conformance, and later M7 V2 fixture strategy.

### DEC-0051 — Compatibility tests extract old V1 fixtures from the JS source
- **Milestone:** M6
- **Status:** accepted
- **Decision:** `tests/Unit/CompatibilityTest.php` parses `oldDoc` and `oldVal` directly from `../yjs/tests/compatibility.tests.js`, then applies the base64 update with PHP and compares the materialized Array/Map/Text state after normalizing PHP `stdClass` values.
- **Why:** The upstream compatibility test embeds very large v13.2.0 update blobs and expected JSON literals. Reading those literals from the JS source keeps the PHP test anchored to the exact upstream test without duplicating hundreds of lines of byte data by hand.
- **Affects:** Compatibility tests, repository layout assumptions for running the port tests beside the sibling `yjs/` source, and any future update of `compatibility.tests.js` fixtures.

### DEC-0052 — Real-client interop fixtures are browser-captured V1 updates
- **Milestone:** M6
- **Status:** accepted
- **Decision:** Add `tests/fixtures/real-client/interop.html` as a deterministic browser fixture page that imports local `yjs/src/index.js` with an import map for `lib0/*`. Captured files are `browser-generated-update.bin` plus `browser-generated.json`, and `php-generated-update.bin` plus `php-generated-browser-applied.json`. `tests/Conformance/RealClientInteropTest.php` asserts PHP decoding/re-encoding of the browser update and fresh PHP regeneration of the browser-validated PHP update.
- **Why:** M6 needs proof against a real JS browser client, not only Node-generated oracle fixtures. The static page lets Chromium execute the actual Yjs source while the PHP test remains deterministic from saved `.bin` captures.
- **Affects:** Real-client interop coverage, fixture regeneration workflow, future browser fixture additions, and any later change to module serving/import-map assumptions.

### DEC-0053 — Subdoc destroy replaces the content carrier doc like JS
- **Milestone:** M6
- **Status:** accepted
- **Decision:** `Yjs\Utils\Doc::destroy()` now mirrors `yjs/src/utils/Doc.js`: when destroying an integrated subdocument, clear the old doc's `_item`, replace the `ContentDoc::$doc` carrier with a new `Doc` with the same guid/options and `shouldLoad => false`, attach that new doc to the item, and transact on the parent doc with the new doc in `subdocsAdded` and the old doc in `subdocsRemoved`.
- **Why:** JS keeps a deleted/destroyed subdoc slot loadable by swapping in a fresh unloaded document. Without this, `subdocs.get(key)->destroy()`, subsequent `load()`, and remote update decoding cannot produce the expected `subdocs` add/remove/load event sequence.
- **Affects:** Subdocument lifecycle events, `getSubdocGuids()`, update decoding of `ContentDoc`, future provider/autoload behavior, and undo/subdoc edge-case tests.

### DEC-0054 — V2 optimized lib0 codecs are standalone classes
- **Milestone:** M7
- **Status:** accepted
- **Decision:** Add `Yjs\Lib0\RleEncoder`/`RleDecoder`, `UintOptRleEncoder`/`UintOptRleDecoder`, `IntDiffOptRleEncoder`/`IntDiffOptRleDecoder`, and `StringEncoder`/`StringDecoder` as standalone classes that wrap the existing `Encoder`/`Decoder` primitives. `StringEncoder` records lengths in JavaScript UTF-16 code units via `Str::utf16Length()`/`Str::sliceUtf16()`, and `UintOptRleEncoder` preserves the negative-zero repeat marker for repeated zero values.
- **Why:** `UpdateEncoderV2` depends on lib0's optimized RLE/diff/string streams, but PHP's existing `Encoder`/`Decoder` classes are final wrappers around binary strings. Standalone wrappers preserve the JS byte format without changing the M0 primitive carrier.
- **Affects:** V2 update codecs, V2 snapshot/delete-set encoding, any future lib0 optimized encoding users, and tests involving non-ASCII strings in V2 string streams.

### DEC-0055 — Real V2 codecs replace the V1-backed shims
- **Milestone:** M7
- **Status:** accepted
- **Decision:** Implement `Yjs\Utils\DSEncoderV2`, `DSDecoderV2`, `UpdateEncoderV2`, and `UpdateDecoderV2` from the JS source. V2-named helpers now default to V2 classes (`applyUpdateV2`, `encodeStateAsUpdateV2`, `mergeUpdatesV2`, `diffUpdateV2`, `encodeSnapshotV2`/`decodeSnapshotV2`, `encodeStateVectorFromUpdateV2`, `parseUpdateMetaV2`, `decodeUpdateV2`, `snapshotContainsUpdateV2`, and `obfuscateUpdateV2`). V1 public helpers still force V1. `convertUpdateFormatV1ToV2()` uses a V1 decoder and V2 encoder; `convertUpdateFormatV2ToV1()` uses a V2 decoder and V1 encoder. `createDocFromSnapshot()` now writes and applies the JS-default V2 restore update.
- **Why:** M7 removes the deferral from DEC-0003/DEC-0029/DEC-0045 and matches the upstream JS defaults. Keeping V1 wrappers explicit preserves existing V1 byte contracts while the V2 APIs finally emit/read real V2 bytes.
- **Affects:** All V2 update/snapshot callers, offline update utilities, snapshot restore, conversion/obfuscation, and any code that previously relied on V2-named helpers accepting V1 bytes by default.

### DEC-0056 — V2 key encoding keeps the JS disabled key cache
- **Milestone:** M7
- **Status:** accepted
- **Decision:** `UpdateEncoderV2::writeKey()` writes the current `keyClock`, increments it, and writes the key string, but does not populate `UpdateEncoderV2::$keyMap`, mirroring the commented-out `this.keyMap.set(key, this.keyClock)` line in `yjs/src/utils/UpdateEncoder.js`.
- **Why:** Upstream intentionally leaves key reuse disabled for compatibility with older decoders. Enabling the cache in PHP would produce shorter but non-JS V2 bytes.
- **Affects:** `ContentFormat` encoding, rich-text/XML formatting updates, V2 byte fixtures, and any later attempt to optimize key streams.

### DEC-0057 — `updateV2` event is emitted only when observed
- **Milestone:** M7
- **Status:** accepted
- **Decision:** Add `Yjs\Lib0\Observable::hasObservers()` and emit a transaction `updateV2` event with `UpdateEncoderV2` only when the doc has `updateV2` listeners. The existing `update` event remains V1.
- **Why:** JS checks `doc._observers.has('updateV2')` before paying the V2 encoding cost. PHP needed a small observable helper to mirror that behavior without exposing the observer array directly.
- **Affects:** Provider/sync layers that subscribe to doc updates, V2 fixture capture, future test connectors that choose V2 updates, and transaction cleanup performance.

### DEC-0058 — V2 fixture coverage extends the operation-log oracle
- **Milestone:** M7
- **Status:** accepted
- **Decision:** `tools/gen-fixtures.mjs` now writes `update-codecs-v2.json`, `delete-set-v2.json`, and `update-utilities-v2.json`; existing scenario/convergence fixtures include `updateV2Hex`/`updateV2Hexes` and `snapshotV2Hex` where applicable. The V2 delete-set fixture's large-clock case keeps delete items sorted by clock because V2 delete-set clocks are diff-encoded from the previous delete range.
- **Why:** M7's exit criterion needs JS-byte parity for V2 codec primitives, update utilities, conversions, obfuscation, snapshots, and fuzz-style operation logs. V1 tolerated an unsorted synthetic delete-set fixture because it wrote absolute clocks; V2 must reflect the sorted delete-set invariant produced by real Yjs.
- **Affects:** Fixture regeneration, conformance tests for V2 update utilities and type convergence, and any future synthetic delete-set fixtures.

### DEC-0059 — Pending updates use V2 buffers and full readUpdateV2 recovery
- **Milestone:** post-M7 maintenance
- **Status:** accepted
- **Decision:** `integrateStructs()` and `readAndApplyDeleteSet()` write their unapplied rest buffers with `UpdateEncoderV2`, replacing the V1 stopgap from DEC-0029 now that M7's real V2 codecs exist. `readUpdateV2()` ports the full JS pending path: merging new rest structs into `store->pendingStructs` (lowest missing clock wins, updates merged via `mergeUpdatesV2()`), re-reading `store->pendingDs` on every update, and retrying integration when a new update fills a causal gap. `encodeStateAsUpdateV2()` folds `pendingDs` and a `diffUpdateV2()` of `pendingStructs` into its output, converting V2 to V1 when encoding with `UpdateEncoderV1`. `integrateStructs()` also guards its loop-bottom target dereference with `array_key_exists()`, because `addStackToRestSS()` unsets the target's map entry where JS empties a still-live object reference.
- **Why:** Applying an update out of causal order crashed with a `TypeError` (`count(null)`) where JS parks the structs as pending and recovers, and even without the crash y-php dropped pending state on overwrite and on encode. The internal pending buffers must be V2 bytes because the JS merge/retry/diff helpers parse them with V2 decoders; `tests/fixtures/pending-updates.json` pins byte-for-byte parity with JS for both codec families.
- **Affects:** Out-of-order update delivery, server-side update ingestion, `encodeStateAsUpdate`/`encodeStateAsUpdateV2` output for docs holding pending state, and the pending-updates conformance fixtures.
