# High-latency awareness

A prototype of presence for connections that cannot carry updates every
second: no WebSocket, and polling no faster than every 5 to 15 seconds or
more. It is turned on from Settings → Collaboration ("Awareness
interval"; 0 keeps the built-in realtime awareness) and lives in
`src/awareness/` on the client with one small server class,
`includes/awareness/`.

## The problem with carets

The built-in awareness describes each peer as a caret: a position inside
the shared Yjs document, refreshed every 100 ms and rendered as a colored
cursor with a name flag. That model needs two things a slow connection
does not give it: every editor holding the same document, and updates
arriving before the peer has moved on. Slow it down to one update every
15 seconds and the cursor is always somewhere the peer no longer is. It
also does not exist at all under the intent-log engine, which has no Yjs
document to anchor a position in.

With awareness and document updates possibly on different transports, a
third problem appears: a peer can name a block or a character position
this editor has not received yet. A caret cannot be drawn in content that
is not there. Presence has to survive that instead of silently vanishing.

## What a peer publishes

Once per interval each editor publishes a small beacon. It summarizes the
whole interval rather than sampling an instant:

- `focus`: the block the selection is in right now.
- `recent`: the trail. Every block the user interacted with in the last
  30 seconds, each with the age of that last interaction in milliseconds
  on the sender's clock. An interaction is the selection entering a
  block, leaving it, or an edit inside it, so a block the user sat in for
  a while counts from the moment they left. The focused block is first
  with age 0.
- `edits`: every block the user touched since the previous beacon, with
  the kind of change (edit, insert, remove) and a count.
- `intervalMs`: the sender's own cadence, so receivers judge silence
  without a global constant.

Blocks are named by a `BlockRef` that stays meaningful when the block is
absent locally:

- `syncId`: the durable identity the intent-log and de-rtc engines stamp
  on every block (`metadata.syncId`).
- `clientId`: the editor id, which the yjs-server engine shares through
  the Y.Doc, so it is a cross-peer identity there.
- `name`, `path`, `after` (previous sibling's identity), `parent`, and a
  60-character `excerpt`: enough to say "a Paragraph after the one that
  starts 'Second paragraph…', reading 'A brand new…'".

The beacon is plain JSON and never interpreted by the server. See
`src/awareness/types.ts`.

Which edits count as the local user's is heuristic in this prototype (the
block-editor store does not label changes as local or remote): a change to
the selected block counts while the editor reports typing or within two
seconds of the user moving the selection; inserts and removals count
under the same rule. Every engine already knows exactly which changes are
local, so a production version should take `edits` from the engine and
keep only the beacon shape.

## Two channels

The beacon can travel two ways, chosen by "Awareness channel":

- **Sync transport** (default). The beacon is one more field on the
  framework's awareness state, so it rides the same requests as document
  updates under http-polling, long-polling, and websocket alike. No
  server change. This shows what the cadence feels like, but awareness
  and content always arrive together.
- **WordPress Heartbeat.** The beacon rides the admin Heartbeat request
  (`heartbeat-send` / `heartbeat-tick`), a separate request stream with
  its own interval, stored per post in a transient and answered with the
  other live clients' beacons. This is the split-transport shape: raise
  the site's polling interval and awareness arrives well before the
  content it refers to. Heartbeat's own rules apply (interval 1-3600 s,
  a temporary "fast" mode at 5 s that the channel re-arms, slowdown when
  the window loses focus).

In both modes the built-in caret field is suppressed on the local state,
so peers on the slow mode see block activity only. Presence itself (who
is on the post, the header avatars) keeps coming from the framework.

## What a peer sees

- **A stripe.** A 3 px bar in the peer's color just left of every block
  the peer is in or touched this interval, applied through the public
  `editor.BlockListBlock` filter (a class and CSS variables on the block
  wrapper). Several peers on one block get side-by-side stripes. Hovering
  the stripe shows a label: "Riley is typing in this block", "Riley is
  in this block", "Riley edited this block 8 seconds ago", "Riley added
  this block just now", or "Riley removed this block. That change has
  not reached you yet."
- **Strength from the trail, not from the clock.** When a beacon
  arrives, each trail entry's age sets its stripe: full strength under
  15 seconds, half strength from 15 to 30, and no stripe after 30 (the
  sender does not even send those). The focused block is always full.
  Nothing changes between beacons: a stripe never fades on its own, it
  steps to its new strength when the next beacon lands, with a short
  (250 ms) animation, and a stripe the new beacon dropped animates out
  the same way. Ages are the sender's own measurements, so clock skew
  between machines never matters. The only receiver-clock rule is a
  safety net: a peer silent for four of its intervals (never less than
  a minute) is dropped entirely.
- **Phantoms.** A reference that matches no local block is not dropped.
  It resolves to the nearest block we do have (previous sibling, else
  parent, else the top of the document) and renders there as a dashed
  stripe with a label: "Riley is adding a block here that has not
  reached you yet: Paragraph 'A brand new…'". The moment the content
  lands the phantom turns into an ordinary stripe on the real block. The
  reverse case is handled too: a block the peer removed but we still
  hold shows the removal label on the block.
- **A sidebar panel** ("Collaborator activity"): every peer in words,
  with the block they are in, their 30-second trail with ages and what
  they did in each block, a "Go to block" link for blocks we have, "(not
  received yet)" for blocks we do not, and a countdown to their next
  update. Over a slow channel this list carries
  more than any in-canvas marker.

## Other affordances worth trying at this cadence

Not built here; these follow naturally from the beacon shape.

- **Collision warning.** When the local user focuses a block a peer is
  active in, say so in the block toolbar or as a soft notice. At a
  15-second cadence, concurrent edits to one paragraph are far more
  likely to conflict (intent-log escalates them to review; de-rtc parks
  them), so the best thing awareness can do is steer people apart.
- **List View dots.** A colored dot per peer next to blocks in the
  document outline. Structure without cursors is exactly what a slow
  channel can afford, and List View shows the whole document at once.
- **A longer trail.** The 30-second trail could stretch to minutes at a
  lower strength, so a returning reader sees where the work happened
  while they were away, not only where the peer is now.
- **Reading position.** Add the first and last visible block to the
  beacon. "Riley is reading the introduction" is cheap and useful, and
  reading has no conflict cost.
- **Document distance.** Each engine has a version notion (the
  intent-log sequence, the de-rtc version, a Yjs state vector). Put it in
  the beacon and the receiver can say "Riley's copy is two changes ahead
  of yours", which is the honest thing to show when transports are
  split.
- **Pending-arrival badge.** When a beacon reports edits to a block we
  hold but no matching content has arrived, mark the block "changes on
  the way" rather than only marking blocks we lack.

## Framework changes this points at

- Core-data's typed awareness throws on any field without a registered
  equality check, so a peer carrying an extra field breaks the
  receiver's polling. The registry installs the check for `activity` on
  every awareness instance this plugin creates, in every mode, to stay
  safe. The framework should tolerate unknown fields (deep equality by
  default) or expose a way to register fields.
- The manager exposes the awareness instance only through core-data's
  private hooks. This plugin's engines register the instance themselves
  (`src/awareness/registry.ts`). An engine-facing "awareness field"
  extension point would remove that.
- The realtime caret producer has no off switch; the prototype wraps the
  instance's setter to drop `editorState`. A mode flag on the awareness
  config would be cleaner.
- Presence gates sync behavior (peers present switches local edits from
  deferred to synchronous, and speeds polling). A slow awareness channel
  must not be the only presence signal, which is why this prototype keeps
  the framework's presence on the sync transport.

## Known limitations

- The Heartbeat store is a transient with a non-atomic read-modify-write;
  two clients writing in the same instant can drop one another's entry
  for one tick. The framework's storage already merges sync-transport
  awareness and is where a durable version belongs.
- Local-edit attribution is heuristic (see above).
- Phantom placement is by previous sibling or parent only; a phantom
  whose neighbors are also missing lands at the top of the document.
- The hover label is a CSS pseudo-element, so it cannot be focused or
  read by assistive technology; the sidebar panel carries the same
  information in real text.

## Trying it

1. Settings → Collaboration: set "Awareness interval" to 5 (later 15).
2. Open one post as two users in two browsers; type in one; watch the
   other.
3. For the split-transport case, set "Awareness channel" to WordPress
   Heartbeat and "Polling interval" to 20, then insert a block in one
   window and watch the phantom appear in the other before the content.
