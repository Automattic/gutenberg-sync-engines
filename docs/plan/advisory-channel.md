# Plan: a base transport, an advisory channel, and discovery on the heartbeat

Status: implemented on branch `transport-layers` (2026-09-02). This file
keeps the reasoning; the code is the reference for details.

## The idea in one paragraph

Short polling is the base transport everyone has. It is the only lane
that moves document updates, and it is the lane every peer falls back
to. On top of it, each editor tab opens an optional **advisory channel**
straight to the other tabs editing the same post, browser to browser
over WebRTC. The channel carries two things only: who is where
(presence: cursors, selections, names) and the sentence "the server has
new rows, go and poll". It never carries content. To find each other
and to exchange the handshake messages WebRTC needs, tabs use a tiny
**signaling** service that rides the heartbeat WordPress already sends
from every editor screen. Once a tab is connected to everyone it can
see, it stops polling on a timer and polls only when it has something
to send or when a peer tells it there is something to fetch. A tab that
is alone stops polling altogether after its first handshake. A tab that
cannot reach its peers keeps polling exactly as today.

## What this replaces, and what it keeps

- Keeps every read and write on the REST sync endpoint, behind the
  user's own cookie and nonce. The channel proves nothing; the poll
  proves everything.
- Keeps the transport registry and negotiation untouched. The site
  still announces one transport slug; the advisory channel is a mode of
  the polling manager, like long polling already is.
- Keeps long polling for now. When long polling is the active transport
  and its held request is connected, it turns the advisory channel off:
  the server already wakes it the instant a row lands, so the channel
  would only duplicate that. When long polling is disconnected the
  request to disable is ignored and the channel comes back.
- Leaves the websocket transport as it is. It will become advisory
  too (nudges and presence over the socket, rows over REST), but not in
  this change.
- Replaces the relay-server design in the poll-on-notify investigation
  (`research/poll-on-notify`): same client rules, no relay, no third
  party, and presence gets a fast lane as a bonus.

## The rules, stated plainly

1. **Everyone polls the base transport.** One transport slug is
   announced. There is no mesh of transports. A site can pick one
   upgrade (today: long polling; later: websocket) that composes with
   the base.
2. **The advisory channel is a rumor.** A nudge carries a room name and
   nothing else. Nothing in it moves the cursor, applies a row, or
   proves who wrote what. Presence over the channel is display data,
   never authority: who is *in* the room is decided by the server's
   presence records, not by the channel.
3. **Alone means quiet.** While the server says nobody else is in this
   post's room, the tab schedules no polls. Its own edits still go out,
   one request per typing burst, so the room stays in step with what a
   reload would load (see "Why solo edits still go out" below).
4. **Company without coverage means today's cadence.** If anyone is in
   the room whom this tab cannot reach over the channel, the tab polls
   at the configured interval. "Anyone" means every presence token the
   heartbeat reports AND every client id the last poll's awareness map
   reported. Both lists must be covered by an open channel. Otherwise a
   peer whose WebRTC failed would write rows nobody polls for.
5. **Full coverage means poll on demand, plus a safety poll.** With
   every known peer reachable, the tab polls when it has updates to
   send (debounced), when a peer announces (coalesced, with a floor),
   and on a slow safety timer (25 s). The safety poll catches writers
   who are not on the channel at all: scripts, WP-CLI, a peer whose
   channel dropped mid-write, a taxonomy term created from another
   screen. It also keeps the server's awareness record alive, which the
   long-poll wake and the presence answer read.
6. **Discovery and signaling ride the heartbeat.** Each editor tab has a
   per-tab token, stamped when the page renders and refreshed every
   heartbeat. The heartbeat answer lists the other tokens in the room,
   says whether anyone else is there, and delivers any handshake
   messages addressed to this tab. Sending a handshake message calls
   `wp.heartbeat.connectNow()`, so the sender does not wait for its
   own tick; the receiver still sees it on its next tick.
7. **A transport may switch the channel off, but only while connected.**
   Long polling does this. The request is ignored while that transport
   is disconnected.

## Why solo edits still go out

The spec says a lone tab ceases polling after its genesis handshake. We
read that as "schedules no polls". The tab still sends its own edits
when it has them, debounced to one request per burst. The reason is a
reload: the editor bootstraps from the room, and a room that never saw
the solo edits would clobber the freshly loaded post with the older
shared copy. That trap is exactly why the intent-log and yjs-server
sessions declared `syncWhileSolo` before this change. Holding edits in
the browser until company arrives is the "saved post is the truth"
plan (`queue-solo-syncing`), which changes room lifetime and save
semantics and is a separate decision. Idle solo cost is zero either
way; the difference is one request per typing burst while typing alone.

## What exists now

Server (`includes/class-gutenberg-sync-engines-advisory-presence.php`):

- Per-tab presence tokens in a transient per room (never in sync
  storage: presence reads must not create a room's storage post).
  Stamped at editor page render, refreshed on every heartbeat, removed
  by a leave beacon on `pagehide`, expired after 300 s (a hidden tab's
  heartbeat slows to 120 s, so the TTL must span two beats).
- The heartbeat filter (`heartbeat_received`): records the token,
  stores outgoing handshake messages in per-recipient mailboxes (size
  and count capped, short expiry), and answers with the other tokens in
  the room, whether anyone else is present (tokens plus live sync
  awareness), and this tab's mailbox.
- Page-render settings under `window._gutenbergSyncEnginesSettings
  .advisory`: room, token, whether others are present, the STUN list
  (filterable), the peer cap, and the enabled flag.

Client:

- `src/providers/advisory/signaling.ts`: the heartbeat probe and
  mailbox. Discovered peers, "others present", send/receive handshake
  messages, the leave beacon.
- `src/providers/advisory/channel.ts`: the WebRTC mesh. One peer
  connection and one data channel per discovered tab. The tab with the
  lower token initiates; ICE gathering completes before the offer or
  answer is sent, so a handshake is two heartbeat hops. Messages:
  `hello` (client id), `presence`, `announce`, `bye`. Coverage is
  computed from discovered tokens and the last awareness map.
- `src/providers/http-polling/polling-manager.ts`: the cadence rules
  above, the announce-after-send, the presence overlay (channel
  presence wins over the poll response's older copy for peers on the
  channel), and the long-poll disable hook.
- `src/engines/de-rtc/session.ts`: announces after a commit lands
  through the autosave lane, since those rows never pass through the
  polling manager.

## Failure behavior

The rule: **the client behaves as if the channel did not exist, then
uses it to poll sooner and to show presence faster.**

- Channel never connects (no STUN reachable, symmetric NAT without
  TURN, WebRTC disabled by a privacy extension): coverage stays false;
  today's cadence; nothing lost. TURN is deliberately not required.
- A peer drops off the channel: coverage flips false on the
  `connectionstatechange`; the timer cadence resumes at once.
- Nudge dropped: the safety poll catches up within 25 s.
- Nudge storm: the coalescing delay and the floor bound polls per
  second; the server's existing size and room caps do the rest.
- Heartbeat suspended (10 minutes idle) or the tab hidden (120 s
  cadence): the established channel survives; renegotiation waits for
  focus; the token expires after 300 s if the tab never beats again,
  and peers stop counting it.
- More peers than the cap (8): the channel stands down and everyone
  polls. Full mesh is N(N-1)/2 connections; document editing rarely
  gets there.
- The presence lane is missing (no `wp.heartbeat`, no per-post editor
  screen such as the site editor): the polling manager keeps its
  always-on cadence. Nothing about today's behavior changes there.
- Queued work never waits for a slow timer. A coverage flip re-evaluates
  a pending timer; if that would replace a 1 s timer with the 25 s
  safety timer while updates are already queued (the intent-log undo
  spec caught exactly this: an undo's inverse intents sat unsent for
  25 s), the delay is cut to the on-demand send delay instead. The
  cadence rules decide how often to LOOK for rows; queued rows go out
  promptly regardless.

## What the client must never assume

- That a nudge means new rows exist. Poll and find out.
- That no nudge means nothing happened. Keep the safety timer.
- That the channel knows who is in the room. The server does.
- That the channel is authenticated the way the REST endpoint is.
- That a peer on the channel is the only peer. Check the awareness map.

## Tests

- Jest: `tests/js/providers/advisory/` (signaling payloads and
  mailbox; a two-tab mesh over a fake `RTCPeerConnection` wired through
  an in-memory signaling loop; coverage rules), and the polling manager
  cadence rules (quiet when alone, wake on company, on-demand polls
  under coverage, safety poll, announce coalescing, long-poll disable).
- PHPUnit: `tests/phpunit/gutenbergSyncEnginesAdvisoryPresence.php`
  (token record and expiry, others-present from tokens and awareness,
  mailbox relay with caps, permission fence, leave route, page-render
  settings).
- e2e: `tests/e2e/specs/http-only/collaboration-advisory-channel.spec.ts`
  (two tabs connect over the channel and an edit still propagates;
  idle polling drops to the safety cadence).

## Open questions

- Whether cursor traffic at 250 ms over the channel is too chatty for
  the editor's awareness handling. Easy to slow down.
- Whether the websocket transport should keep delivering rows when it
  becomes advisory, or move to nudges only. Decide when that work is
  picked up.
- When to retire long polling.
