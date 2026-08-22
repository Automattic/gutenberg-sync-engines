# Docs

Start here if you want to:

- **Pick an engine or transport** →
  [engine-comparison.md](engine-comparison.md) — what each engine is,
  how they score against the principles, feature parity, resource
  shapes, and each engine's known gaps.
- **Understand the rules the engines are judged by** →
  [principles.md](principles.md) — the seven acceptance criteria
  (P1–P7).
- **See what actually happens on the wire** →
  [scenarios.md](scenarios.md) — seven concrete situations (solo
  typing, same-paragraph conflicts, machine writes, deep lag…) traced
  through all three engines.
- **Compare transports** → [transports.md](transports.md) — polling vs
  long-polling vs websocket, and the websocket operational notes.
- **Understand de-rtc's relationship to its upstream design** →
  [de-rtc-fidelity.md](de-rtc-fidelity.md) — the audit of our port
  against the Distributed Editing vision.
- **See what we'd change with hindsight** →
  [architecture-decisions.md](architecture-decisions.md) — four early
  decisions worth revisiting, and what each change would cost.
- **Look up a term** → [glossary.md](glossary.md) — the project's own
  vocabulary in plain words.

Open work items live in `V1.md` at the repo root, each with acceptance
criteria. Notable shipped changes are recorded in `CHANGELOG.md`. To
regenerate every number behind these docs on YOUR hardware, run
`npm run bench` against a running tests env (see
`tests/benchmarks/README.md`).
