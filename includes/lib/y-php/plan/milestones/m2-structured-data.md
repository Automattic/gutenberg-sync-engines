# M2 — Structured data: Doc + YMap + YArray (overview)

The keystone milestone — it builds the entire CRDT core and the first real interop. It is **split into six parts** (`m2-1` … `m2-6`); this file is the map. Each part is a self-contained task with its own exit criterion; assign one agent per part using `AGENT_BRIEF_TEMPLATE.md`.

## Goal (milestone-level)

A working `Doc` with `YArray` and `YMap`, plus `applyUpdate` / `encodeStateAsUpdate` / `encodeStateVector`, converging across simulated clients and byte-matching JS.

## Prerequisites

M0 (primitives + tooling gate) and M1 (stubs + translated tests + harness).

## How it was split, and the task-size rule

A good part is **one cohesive layer that can turn some check green on its own** (unit tests, byte-fixtures, or a round-trip) — roughly 1–4 substantial source files, holdable in one agent's context, reviewable in one sitting.

- Layers *below* the engine (codecs, stores, structs/content) isolate cleanly and are byte-fixture-verifiable → M2.1, M2.2, M2.3.
- The **integration core** (`Item` ↔ `AbstractType` ↔ `Transaction` ↔ `Doc` ↔ `encoding`) is co-dependent — splitting it yields pieces no test can exercise — so it stays whole even though it's the largest → M2.4.
- The public types *above* the engine isolate cleanly again → M2.5, M2.6.

## Parts and dependency order

```
M2.1 ids-and-codecs        ID, UpdateEncoderV1/DecoderV1       → encoder byte-fixtures
  └─ M2.2 store-and-deleteset   StructStore, DeleteSet         → unit + DS byte-fixtures
       └─ M2.3 structs-and-content  AbstractStruct, GC, Skip, Content*  → per-content byte-fixtures
            └─ M2.4 integration-core  Item, AbstractType, Transaction,
                                       Doc, encoding, sync subset → doc.tests + round-trip fixtures
                 ├─ M2.5 yarray   full YArray API → y-array unit+fuzz+conformance
                 └─ M2.6 ymap     full YMap API   → y-map unit+fuzz+conformance   (parallel with M2.5)
```

M2.1 → M2.4 are strictly sequential. M2.5 and M2.6 can run in parallel once M2.4 lands. V1 encoding only throughout (DEC-0003).

| Part | File |
|---|---|
| M2.1 IDs & update codecs | [m2-1-ids-and-codecs.md](m2-1-ids-and-codecs.md) |
| M2.2 Struct store & delete set | [m2-2-store-and-deleteset.md](m2-2-store-and-deleteset.md) |
| M2.3 Structs & content | [m2-3-structs-and-content.md](m2-3-structs-and-content.md) |
| M2.4 Integration core | [m2-4-integration-core.md](m2-4-integration-core.md) |
| M2.5 YArray | [m2-5-yarray.md](m2-5-yarray.md) |
| M2.6 YMap | [m2-6-ymap.md](m2-6-ymap.md) |

## Milestone exit criterion

All six parts complete: `doc`, `y-map`, `y-array` unit + fuzz tests green; their fixtures byte-match real JS in both directions; multi-user convergence matches JS bytes for many seeds; `composer lint` clean. After M2, the next type milestone is M3 (YText).
