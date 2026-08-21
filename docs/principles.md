# Core principles

These seven rules are the acceptance criteria this project is judged
against. They are not aspirations. They synthesize the team's problem
statement and principles ([Collaborative editing: problems and
strategies](https://collaborativeediting.wordpress.com/2026/08/04/collaborative-editing-problems-and-strategies/))
and the Distributed Editing design principles ([Distributed Editing with
unlimited
Codex](https://collaborativeediting.wordpress.com/2026/07/02/distributed-editing-with-unlimited-codex/)).
Every engine decision is measured against them, and every violation is
named — including the ones our own porting choices introduced. See
[engine-comparison.md](engine-comparison.md) for how each engine scores.

- **P1 — The server is the authority.** WordPress stands in the path of
  every update: it authorizes, attributes, and can inspect each one at
  ingest. A relay that cannot say who wrote what cannot enforce
  capabilities — an admin's save must never launder a script tag an
  author injected into the shared document. The retired yjs-relay engine
  failed this principle, and that is why it was retired.
- **P2 — No edit is ever silently lost.** Reloads, network loss, delayed
  saves, out-of-band writes: the design must degrade toward escalation
  and review, never toward disappearance. The benchmark's zero-lost-work
  oracle certifies this per engine on every scenario.
- **P3 — Real conflicts are surfaced, not hidden.** "Conflict-free"
  hides conflicts. When changes overlap meaningfully, the system detects
  it and asks a human — while taking care not to overburden humans with
  constant review. The escalation rate on contended workloads is a
  first-class metric, not an afterthought: too high is a failure of
  mergeability, and *silently zero is a failure of honesty*.
- **P4 — Collaboration is not just for humans.** Agents, CLI tools,
  REST/XML-RPC integrations, and plugins must be able to use existing
  WordPress APIs without disrupting collaborative sessions — and ideally
  participate in them meaningfully. A scheduled integration that
  read-modify-writes a post must not erase five minutes of two editors'
  work with no record that a conflict existed.
- **P5 — Cheap hosting is normal hosting.** Functional everywhere,
  progressively enhanced where the host commits resources. Nothing on
  the core path may assume database or process topology beyond what
  WordPress Core itself assumes. Locks, in particular, must be
  implemented the way Core would implement them.
- **P6 — Host economics are measured, not asserted.** Resource usage is
  demonstrated with repeatable benchmarks. The comparison guide
  deliberately carries no numbers (they go stale and mislead); it
  describes stable shapes and points at `npm run bench`.
- **P7 — Capture intent and identity, not snapshots.** Semantic
  operations ("split block", "move block") and stable block identity
  make merges match what the user actually did. Diffing before/after
  snapshots reconstructs a guess — and a guess is what mangles prose
  when edits collide.
