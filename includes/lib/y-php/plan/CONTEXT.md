# y-php — Shared Context (read this first, on every task)

This is the **constitution** for the Yjs → PHP port. Every implementation agent reads this file in full before touching code. It holds the durable rules that span all milestones.

- For strategy and rationale, see [overall.md](overall.md) — you do **not** need it to implement.
- For choices earlier agents already locked in, read [DECISIONS.md](DECISIONS.md) — you **must** read it before writing code, and append to it when you make a cross-cutting decision.
- For your specific task, read your milestone file in [milestones/](milestones/).

## What we're building

A **PHP 7.4+** port of **Yjs v13.6.31** for server-side WordPress, with **full binary wire-interop** with live JS clients. The source of truth is the JS implementation in [yjs/src/](../../yjs/src/) and its tests in [yjs/tests/](../../yjs/tests/).

| Decision | Choice |
|---|---|
| Interop | Full binary wire-interop with live JS clients (byte-level conformance is P0) |
| Type scope | All core types: Text, Array, Map, Xml* |
| PHP target | PHP 7.4+ floor (WordPress version compatibility) |
| Coding standard | WordPress Coding Standards (WPCS) via PHPCS, minus the naming sniffs |
| Test strategy | Hybrid: translate tests to PHP **and** validate bytes against real JS output |

## Prime directive: match JS exactly

**The wire format is the contract.** PHP output must be **byte-identical** to JS for the same logical operation. When in doubt, mirror the JS source line-for-line rather than "improving" it.

- Do **not** refactor away the struct/content class hierarchy — `instanceof` checks depend on it.
- Do **not** reorder map/object iteration — it changes encoded bytes.
- Do **not** change integer or float handling for "cleanliness."
- Cleverness that changes bytes is a bug, not an optimization.

## Repository layout

The **project/package root is `y-php/`**. Run Composer, PHPCS, PHPUnit, fixture generation, and any build/test commands from `y-php/`, and create implementation files under `y-php/src`, `y-php/tests`, and `y-php/tools`. The parent workspace (`/Users/alec/projects/yjs`) only contains sibling JS repositories used as source references; do not create PHP package files in the parent-level `src/`, `tests/`, or `tools/` directories.

```
y-php/
├── composer.json            # PSR-4 autoload "Yjs\\" -> src/
├── src/
│   ├── Lib0/                # ported lib0 primitives
│   ├── Structs/             # AbstractStruct, Item, GC, Skip, Content*
│   ├── Types/               # AbstractType, YArray, YMap, YText, YXml*
│   ├── Utils/               # Doc, Transaction, StructStore, DeleteSet,
│   │                        #   UpdateEncoder, UpdateDecoder, Encoding, Updates,
│   │                        #   Snapshot, RelativePosition, UndoManager, EventHandler
│   └── Yjs.php              # public façade mirroring yjs/src/index.js
├── tests/
│   ├── Support/             # ported testHelper: TestConnector, TestYInstance, compare()
│   ├── Unit/                # translated *.tests.js (one PHP class per JS file)
│   ├── Conformance/         # fixture-driven byte-for-byte checks vs JS oracle
│   └── fixtures/            # *.bin + *.json captured from real JS yjs (generated)
└── tools/
    └── gen-fixtures.mjs     # Node script: drives real yjs, dumps fixtures
```

The library is namespaced `Yjs\` and ships as a Composer package.

## Byte-compatibility hazards (the traps that break interop)

Every one of these is something the conformance layer is designed to catch. Read before writing encoder/decoder code.

1. **`Uint8Array` → PHP binary string.** One wrapped `Buffer` representation, chosen in M0 (see DECISIONS). Do not pass raw strings around ad hoc.
2. **No `>>>` / unsigned 32-bit.** PHP `>>` is arithmetic and ints are 64-bit. Mask with `& 0xFFFFFFFF` and emulate unsigned right shift. Client IDs and clocks are unsigned 32-bit.
3. **`writeAny` number dispatch must match exactly** (see `writeAny` in `lib0/encoding.js`): integer with `abs(n) <= 2^31-1` → type 125 varint; else float32 if it round-trips → type 124; else float64 → type 123.
4. **`undefined` (127) vs `null` (126).** JS distinguishes them; PHP has only `null`. Decide the mapping in M0 and record it. The decoder must not choke on 127.
5. **`bigint` (122).** PHP ints are 64-bit; beyond that needs GMP/BCMath or a string carrier. Edge case — don't crash on it.
6. **Float endianness.** `writeFloat32/64` use big-endian (`littleEndian = false`). Use matching big-endian `pack()` codes, not native order.
7. **UTF-8 string length.** `writeVarString` prefixes the **byte** length. Use `strlen`, never `mb_strlen`.
8. **Object key ordering.** `writeAny` iterates `Object.keys` in insertion order; PHP assoc arrays preserve insertion order — keep it that way everywhere.
9. **`instanceof` constructor checks.** Keep struct/content class shapes 1:1 with JS.
10. **Number precision in JSON.** Compare structurally, not as JSON strings (`1` vs `1.0`).
11. **Performance.** PHP is slower than V8; scale the fuzz `--repetition-time` budget down and push heavy runs to CI.

## lib0 conventions

lib0 modules are ported into `src/Lib0/` under namespace `Yjs\Lib0`, one class per module. Keep function names identical to the JS (`writeVarUint`, `readVarInt`, etc.) so ports read against the source. The load-bearing modules are `Encoding`, `Decoding`, `Binary`, `Buffer`, and `Prng` (Xoroshiro128plus + Xorshift32, ported **exactly** so seeds reproduce JS scenarios).

## PHP language baseline (PHP 7.4)

Target floor is **PHP 7.4** for WordPress compatibility. Write to the 7.4 feature set — these 8.0+/8.1+ features are **not** available:

- **No enums** → use a `final class` with `const` values (Yjs already uses numeric type refs, e.g. content-type constants).
- **No `readonly` properties** → plain properties; enforce immutability by convention.
- **No constructor property promotion** → declare properties, assign in the constructor body.
- **No native union types** (`int|string`) → use PHPDoc `@param`/`@return`; native typehints only where single-typed.
- **No `match`** → use `switch`. **No** named arguments, nullsafe `?->`, first-class callable `$fn(...)`, or `mixed`/`never`/`static` return types.

Available and encouraged in 7.4: **typed properties**, **arrow functions** (`fn() =>`), null-coalescing assignment (`??=`), spread in array literals. Native types are weaker here, so lean on **PHPDoc** types to keep static analysis useful — the project still avoids `mixed`-as-an-escape-hatch.

## Coding standard: WPCS minus naming

Produced PHP must pass **WordPress Coding Standards** (WPCS) via PHPCS, with three deliberate exclusions so the port mirrors the Yjs API (see DEC-0005):

- Keep the **camelCase Yjs API and internal names** (`getArray`, `encodeStateAsUpdate`, `writeVarUint`) — exclude `WordPress.NamingConventions.ValidFunctionName` and `…ValidVariableName`.
- Use **PSR-4 filenames** (`Item.php`, not `class-item.php`) — exclude `WordPress.Files.FileName`.

Everything else WPCS enforces applies: Yoda conditions, spacing/alignment, array syntax, security/escaping sniffs. **Run `composer lint` before declaring any task done**; auto-fix with `composer lint:fix`.

## Tooling

Dev dependencies (`composer require --dev`):

- `squizlabs/php_codesniffer` — `phpcs` / `phpcbf`
- `wp-coding-standards/wpcs` (^3) — the WordPress ruleset
- `phpcompatibility/phpcompatibility-wp` — flags code that breaks the PHP 7.4 floor (`testVersion`)
- `dealerdirect/phpcodesniffer-composer-installer` — registers installed standards with PHPCS
- `phpunit/phpunit` — the test runner
- *(recommended)* `phpstan/phpstan` — compensates for 7.4's weaker native types via PHPDoc

`phpcs.xml.dist` at the repo root pins the ruleset (created in M0):

```xml
<?xml version="1.0"?>
<ruleset name="y-php">
  <description>WPCS for y-php, minus naming sniffs (camelCase API mirrors Yjs).</description>
  <file>src</file>
  <file>tests</file>
  <arg name="extensions" value="php"/>
  <arg value="sp"/>
  <config name="testVersion" value="7.4-"/>
  <rule ref="PHPCompatibilityWP"/>
  <rule ref="WordPress">
    <exclude name="WordPress.NamingConventions.ValidFunctionName"/>
    <exclude name="WordPress.NamingConventions.ValidVariableName"/>
    <exclude name="WordPress.Files.FileName"/>
    <exclude name="Generic.Commenting.DocComment.MissingShort"/>
    <exclude name="Squiz.Commenting.FunctionComment.Missing"/>
    <exclude name="Squiz.Commenting.FunctionCommentThrowTag.Missing"/>
    <exclude name="Universal.NamingConventions.NoReservedKeywordParameterNames"/>
  </rule>
</ruleset>
```

M0 keeps the `WordPress` base but excludes docblock boilerplate and reserved-keyword parameter-name sniffs so the port can mirror lib0/Yjs names without drowning in non-behavioral comments. Composer scripts: `test` → `phpunit`, `lint` → `phpcs`, `lint:fix` → `phpcbf`. Regenerate fixtures with `node tools/gen-fixtures.mjs`.

## Testing — how the spec validates PHP

Four layers (full detail in [overall.md](overall.md)):

1. **Translated tests** (`tests/Unit/`) — PHP/PHPUnit ports of `*.tests.js` for the red→green loop. Carries drift risk, closed by Layer 2.
2. **Spec-against-PHP** (`tests/Conformance/`) — **(a)** seed-reproducible fuzz scenarios (exact PRNG → same seed runs the real spec logic in PHP) and **(b)** differential artifact fixtures from real yjs. **This is the byte-interop guarantee.**
3. **Operation-log bridge** — defined option, build only if fixtures prove too coarse.
4. **Live object proxying** — explicit non-goal.

Run tests with `composer test`. Regenerate fixtures with `node tools/gen-fixtures.mjs`.

**M0 is a hard gate:** no agent starts M2+ until the encoding-primitives conformance test byte-matches JS. A wrong varint in M0 surfaces as a baffling failure milestones later.

## Global definition of done

All 208 translated tests pass; the fuzz suite converges across users for many seeds matching JS; every fixture byte-matches real yjs output in both directions; produced code passes `composer lint` (WPCS minus naming) and stays within the PHP 7.4 feature set.

## Protocol for every task

1. **Read** this file, then [DECISIONS.md](DECISIONS.md), then your milestone file.
2. **Reuse** primitives and conventions recorded in DECISIONS — never re-invent them.
3. **Implement** by mirroring the referenced `yjs/src/...` files. Stay within your milestone's scope.
4. **Append to DECISIONS.md** any choice a later milestone could depend on (a shared primitive, a representation/naming choice, an encoding workaround, a deviation from JS, a skipped/adapted test). Use the format at the top of that file. This is mandatory.
5. **Stop and report** at your milestone's exit criterion. Do not start the next milestone.
