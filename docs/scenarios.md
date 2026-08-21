# How the data flows: scenario narratives

Numbers change; these flows don't. Each narrative traces one concrete
situation through all three engines, and names the principle at stake
(see [principles.md](principles.md)). Read
[engine-comparison.md](engine-comparison.md) first for what each engine
is.

## A. One editor types a sentence (the solo baseline)

- **intent-log**: Keystrokes hit the canvas immediately. Capture waits
  for the burst to quiet (a deliberate delay forced by core-data's
  update ordering), diffs the editor tree against the document state
  that tree reflects, and authors typed intents into the outbox at that
  observed seq. The next poll sends them; the server takes the room
  lock, transforms (a no-op solo), appends rows, and acks dispositions.
  The replica absorbs the authoritative rows, and roughly a poll cycle
  after the burst quiets, the unit "settles". Undo works throughout: a
  still-pending unit cancels (outbox removal plus a wire-chasing
  `cancel` row), a settled unit inverts.
- **yjs-server**: Keystrokes apply to the local Y.Doc instantly, so
  undo is armed from the first character. The provider encodes an
  incremental binary update; the next poll delivers it; the server
  decodes the canonical doc, merges, re-encodes, and stores the diff
  row. No lock, no transform.
- **de-rtc**: Keystrokes edit the doc and mark it dirty. When the burst
  settles and no commit is in flight, the client COMMITS its WHOLE
  content against the version it last incorporated — through the
  ordinary autosave endpoint (`WP_De_RTC_Autosave_Commits` intercepts
  the commit shape; the room transport carries no session proposals).
  The server three-way-merges (a fast-forward solo), claims the version
  advance (an uncontended CAS write), persists canonical once in the
  room's chained options row, and stores a ~200-byte `announce`
  (version + content hash + property registers). The next poll delivers
  the announce; the hash matches the typist's own content, so it
  advances its version downloading nothing.

## B. Two editors, different blocks (the common concurrent case)

All three merge losslessly; they differ in *how* and in *what travels*.
intent-log transforms each editor's intents over the other's rows — the
transforms are no-ops because the frames don't intersect. yjs-server's
CRDT merges the updates commutatively. de-rtc three-way-merges each
whole-content commit against base and current: each editor's block
change is a sole-writer change to its block, so both land. The upload
side carries each editor's entire document per commit; the download
side is an announce whose hash doesn't match (the peer merged new
work), so each editor fetches ONE synthesized snapshot of the merged
canonical (P5/P6: de-rtc pays in upload bytes and in a
document-per-incorporation download — no longer in stored rows).

## C. Two editors, the same paragraph (the policy separator)

- **intent-log**: When both edits are observed before the next burst,
  offsets transform and the texts merge. The residual: while this editor
  is still *behind* on the peer's change to the same paragraph, the later
  keystrokes of its burst escalate as `frame-conflict` — parked in the
  review lane, never lost — and normal merging resumes once the peer's
  change is observed. An over-escalation rate problem (P3 honored,
  arguably too eagerly; the escalation-criteria fixture polices the
  rate).
- **yjs-server**: Character-level CRDT merge interleaves both texts
  deterministically; block-attribute (register) collisions resolve by
  silent last-writer-wins. Nothing surfaces to a human (P3 violation,
  documented policy).
- **de-rtc**: The peer's accepted commit announces mid-burst; the
  local hash disagrees, so the client fetches the canonical it names
  (one synthesized snapshot). The client cannot merge (clients never
  merge) and cannot apply it verbatim (that would clobber unsent
  keystrokes), so it *incorporates*: adopts canonical blocks it hasn't
  touched and keeps its own version of the contested block — but it
  also RECORDS the version that block's text was really written
  against, and its next commit declares it (`blockBaseVersions`).
  The server merges the contested block from its TRUE base:
  non-overlapping concurrent edits to the same block merge (both texts
  land), true overlaps park for review at block grain while
  the clean remainder lands. The silent block-level last-writer-wins
  this moment used to cause is retired; and the
  interaction model matches the vision's shape: the colliding
  incorporation raises ONE pending item on the review surface (later
  peer edits to the same block refresh it rather than stacking),
  resolved by explicit Adopt (take the latest canonical form) or
  Reject (keep yours — the recorded base keeps the next server merge
  honest). One timing rule guards the burst itself: while the server
  has merged PEER work into this client's own accepted commit (a newer
  version exists whose content the client does not hold yet), the
  client holds its commit lane — committing against the dead pre-merge
  base would have the server treat its own just-accepted keystrokes as
  a foreign concurrent change and park them (the fuzzer found exactly
  this eating the tail of bursts that straddled a commit round trip).
  The residual: a map-less legacy client still presents
  sole-writer changes. Structural divergence still parks the commit
  whole.

## D. Edit versus remove (one client types into a block another removes)

intent-log escalates the trailing side: if the removal lands first, the
trailing keystrokes park as `target-deleted`; if the text lands first,
both apply and the token vanishes with the removed block. yjs-server
escalates nothing — CRDT deletion dissolves the edit with the deleted
block, deterministically and invisibly. de-rtc still escalates the
most: the structural change shifts the base under the whole-content
commit, and structural divergence is exactly the class per-block
salvage refuses — the trailing commit parks whole (roughly one
escalation per contended pair; per-block salvage removed the *collateral* from
non-structural rounds). The benchmark's `remove-contention` scenario
measures exactly this spread.

## E. An author without `unfiltered_html` pastes risky markup (P1)

- **intent-log**: The intent escalates at ingest as requiring approval
  and parks; a privileged reviewer's restore IS the approval.
- **yjs-server**: The offending blocks are replaced with their
  kses-sanitized form and the compensating delta broadcasts. WordPress's
  filter-on-save semantics at per-update grain — the protected markup is
  gone and no human ever reviews it. Coarser than the other two by
  design.
- **de-rtc**: Per-block sequestration, upstream's model: exactly the
  risky blocks revert to their base form and park for review while the
  safe remainder of the commit merges and lands. Restore re-proposes
  them under the RESTORER's capability, so restore is the approval.
  Whole-proposal escalation remains the fallback for freeform
  boundaries.

## F. An out-of-band machine write lands mid-session (P4 — de-rtc solves it; the others don't)

A scheduled integration fetched the post before the session and writes
back its modified copy while two editors are collaborating.

- **de-rtc**: a *cooperating* integration passes
  `base_version` with its save and gets a genuine three-way merge
  through the room — concurrent session work survives, conflicts
  reject the save with a 409 (and park for review). An *unaware*
  integration is healed after the fact: the next room access detects
  the write — an aware save's embedded `content_hash` matches its own
  content; this one doesn't — and heals it as an ordinary collaborative
  update.
  If the integration round-tripped the embedded sync-meta, the server
  three-way-merges from that base and the editors' concurrent work
  survives alongside the integration's changes; a metaless replacement
  converges the room to the accepted post state (prior canonical stays
  in history); a stale copy heals nothing (rollback guard); an edit
  colliding with concurrent session work parks for review. Connected
  editors see the external change arrive like any peer's edit.
- **intent-log**: a *cooperating* integration declares
  `intent_log_base_seq` and its save diffs into typed intents — the
  session's concurrent work merges by transform, register collisions
  park for review, and the save lands as the merged canonical. An
  *unaware* intent-log writer still bypasses the room: canonical
  diverges from `post_content` and the session's next materializing
  save clobbers the write — this engine has no divergence-detection
  stamp yet (an accepted v1 residual).
- **yjs-server**: the room never learns about the write in either
  variant — the content-loss scenario stands, as this engine's
  accepted, documented limitation (a diff-to-CRDT lane would invent
  semantic operations no writer expressed).

## G. A lagging client comes back (deep lag, reload, reconnection)

- **intent-log**: Exact re-send, idempotent by intentId; old intents
  transform over everything that landed meanwhile. If the room compacted
  past the client's cursor, it re-bootstraps from the retained
  checkpoint (undo stacks clear — inverse derivation needs the log).
  The one P2 window: outbox intents that were never acked die with a
  reloading tab; the loss is visible when it was an undo (the undone
  edit resurrects).
- **yjs-server**: Re-bootstrap from snapshot + tail; the client uploads
  its own full state as an ordinary update — idempotent, the server
  diffs out what it already has. Under racing lock-free ingests a client
  can be asked to resync (`resync-required` void); it heals by the same
  full-state upload, one extra round trip, nothing lost.
- **de-rtc**: The client re-commits its doc's current state; if the
  lost send actually landed, the re-commit merges as a no-op (and its
  announce's hash confirms it). A stale
  base within the engine's 20-version snapshot window is fine — that's
  what the three-way merge is for (though cumulative staleness escalates
  more). Beyond the window the server first mines post revisions for
  the base (each aware save embeds its own snapshot window),
  so even arbitrarily old bases usually merge; only a base no revision
  carries voids as `unknown-base-version`, and the client retries
  against a fresher base: fetch canonical (one synthesized snapshot),
  rebase, re-commit. The
  benchmark models one retry per edit; nothing is lost either way.
