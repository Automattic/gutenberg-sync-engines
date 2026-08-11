# Agent Brief Template

Copy this to launch an implementation agent for a milestone. Fill the `{{...}}` slots. Keep it short — the detail lives in the referenced files, not in the prompt.

---

You are implementing **milestone {{MILESTONE_ID}}** of the Yjs → PHP port.

**Read these before writing any code, in order:**
1. `y-php/plan/CONTEXT.md` — the shared rules for the whole port. Follow them exactly. The prime directive is **byte-for-byte parity with the JS source**.
2. `y-php/plan/DECISIONS.md` — choices earlier agents already locked in. You **must reuse** the primitives and conventions recorded there. Do not re-invent the byte buffer, the `>>>` helper, encoding workarounds, etc.
3. `y-php/plan/milestones/{{MILESTONE_FILE}}` — your task: scope, the exact `yjs/src/...` files to port, the tests to turn green, and your exit criterion.

**While working:**
- Port from the JS source referenced in your milestone file. Mirror it closely; do not redesign it.
- Stay strictly within this milestone's scope. Do **not** start later milestones.
- Write to the **PHP 7.4** baseline (no enums/readonly/union-types/match — see CONTEXT) and keep the **camelCase** Yjs API.
- Run tests (usually `composer test`) and get the tests listed in your milestone file to green.
- Code must pass `composer lint` (WPCS minus naming sniffs); auto-fix with `composer lint:fix`.

**Before finishing — log your decisions:**
- For every choice a later milestone could depend on (a shared primitive, a naming/representation choice, an encoding workaround, a deviation from JS, a skipped or adapted test), **append an entry to `y-php/plan/DECISIONS.md`** using the format documented at the top of that file. This is mandatory. Rule of thumb: if another agent would have to guess what you did, it goes in DECISIONS.

**Definition of done for this task:**
- {{EXIT_CRITERIA — copy the milestone's exit criterion}}
- `composer lint` passes clean.
- All cross-cutting decisions recorded in DECISIONS.md.
- **Stop and report.** Do not proceed to the next milestone.

---

### Notes on using the template

- **One milestone per agent.** The milestone files are scoped so a single agent can own one end-to-end. M2 is the largest (Item + AbstractType) and may warrant its own sub-decomposition.
- **Run milestones in order.** M0 is a hard gate — do not launch M2+ until M0's conformance test byte-matches JS. M1 (stubs + translated tests) must exist before M2+ have anything to turn green.
- **`{{TEST_CMD}}`** is normally `composer test`, or a filtered run like `composer test -- --filter YArray` to focus a milestone.
- **After each milestone, skim the new DECISIONS entries yourself** before launching the next agent — it's the cheapest way to catch a drift before it compounds.
