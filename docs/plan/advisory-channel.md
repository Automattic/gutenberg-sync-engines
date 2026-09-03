# Plan: a base transport, an advisory channel, and discovery on the heartbeat

Status: implemented on branch `transport-layers` (2026-09-02, revised 2026-09-03). This file
keeps the reasoning; the code is the reference for details.

## The idea

Short polling is the base transport everyone has. It moves:

-   base presence (who is in the room):
    -   author IDs, names, avatars (everything needed to show the "who is here" list)
    -   signaling and offers for WebRTC handshakes
-   enhanced presence (cursors, selections)
-   document updates

Short polling can implement every facet of collaboration, albeit slowly.
This base transport can be enhanced in two ways:

### Advisory channel

The advisory channel moves:

-   base presence (who is in the room):
    -   author IDs, names, avatars (everything needed to show the "who is here" list)
-   announcements:
    -   "the server has new rows, go and poll"

The advisory channel can be established in three ways:

1. Via WebRTC, using signaling on short polling requests / responses. (default)
2. Via WebRTC, using signaling on the heartbeat. (default)
3. Via an optional transport such as websockets. Such a transport declares that
   it is advisory-only, which disables the default WebRTC channel while retaining
   the base short polling transport.

A client who successfully connects to an advisory channel can poll on demand, rather
than on a short timer. A "backup" timer is still needed to catch updates from peers
who are not on the channel (e.g., bots or WP CLI commands that save directly against
the server), but it can be much slower than the default short polling cadence.

Since we will only be providing signaling and (public) STUN servers for the default
WebRTC channel, the advisory channel must be considered optional. If it cannot be
established, the client falls back to polling on a short (configurable) timer.

An example sequence using the default WebRTC advisory channel (offer on
discovery: an offer is tied to one peer connection, so a tab publishes
its token first and offers once it knows whom to offer to):

-   User A opens a post in one tab. It polls with a "genesis update" as
    well as its signaling token. Nobody else is there, so A settles on
    the slow safety poll (25 s) and its heartbeat (10 s).
    -   Any edits made by the user are queued until another user is
        present (flushed before a save and when the tab goes hidden).
-   User B opens the same post in another tab. It polls with a "genesis
    update" as well as its signaling token. The response lists the other
    tokens in the room (A's) and says someone else is present, so B polls
    at the company cadence.
-   A learns of B on its next tick (heartbeat or safety poll; up to
    10 s — nothing B does can bring that forward). A now has company: it
    releases its queued updates and polls at the company cadence.
-   The lower token initiates: it creates an offer and sends it with its
    next request (a poll when the loop is active, else a heartbeat beat
    forced by `connectNow()`). The other tab receives it on its next
    poll, about a second later, and answers the same way.
-   A and B now send base presence and announcements to each other over
    the WebRTC advisory channel, and both drop to on-demand polling plus
    the safety poll.
-   When User A makes an edit, it sends the update to the server via
    short polling, plus an announcement over the advisory channel to
    User B that there are new rows to poll.

### Replacement channel

A replacement channel replaces the base transport entirely on successful connection.
It moves everything the base transport does. It declares itself as "replacement".
Once successfully connected, the client stops polling on the base transport and the default
advisory channel.

An example would be the `websocket` transport, which can be used to move all updates and
presence over a single socket connection. Therefore, an advisory channel is not needed.

The client would fall back to short polling if the replacement channel fails to connect,
or if it is disconnected after a successful connection.

## Plugin settings

Transport plugin settings therefore have two parts:

1. An optional replacement channel slug, e.g., `long-polling` or `websocket`.
    - If a replacement channel is selected, the advisory channel setting is disabled.
2. The advisory channel slug, e.g., `web-rtc` (default) or `websocket-advisory` (future bundled transport).

## The rules, stated plainly

1. **Everyone polls the base transport.** One transport slug is
   announced. There is no mesh of transports. A site can configure a
   replacement transport that supplants the default base transport and
   disables the advisory channel.
2. **The advisory channel is a rumor.** A nudge carries a room name and
   nothing else. Nothing in it moves the cursor, applies a row, or
   proves who wrote what. Presence over the channel is display data,
   never authority: who is *in* the room is decided by the server's
   presence records, not by the channel. The "who is here" list is the
   union of the server's answer and the channel's; a peer whose WebRTC
   failed is still a person in the room.
3. **Alone means quiet.** While the server says nobody else is in this
   post's room, the tab polls only on the 25 s safety cadence (the same
   timer full coverage uses; it catches a script or WP-CLI saving the
   post meanwhile). Updates are queued until another peer arrives, and
   flushed before a save and when the tab goes hidden (see "Solo
   editing" below). De-rtc is exempt: its commits ride the autosave
   lane and its undo stack is its own accepted rows, so it keeps
   sending.
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
6. **Discovery and signaling ride polling and the heartbeat.** Each editor tab has a
   per-tab token, stamped when the page renders and refreshed every
   heartbeat. The polling and heartbeat answer lists the other tokens in the room,
   says whether anyone else is there, and delivers any handshake
   messages addressed to this tab. A handshake message rides the next
   poll when the loop is active (about a second at the company
   cadence), else the sender calls `wp.heartbeat.connectNow()`; the
   receiver still sees it on its own next request.
7. **A replacement transport may switch the channel off, but only while connected.**
   Long polling does this. The request is ignored while that transport
   is disconnected.

## Solo editing: held updates

A lone tab holds its updates until company arrives. Two cases matter:

-   **A peer joins.** The joiner bootstraps from the room without the
    held edits, the lone tab learns of the joiner on its next tick,
    releases its queue, and the engine merges the release as a late
    concurrent batch. The joiner converges on its next poll. This is a
    late merge, not a clobber.
-   **Save, then reload.** This is the real trap: a save writes the post
    while the room never saw the edits, and the reload bootstraps from
    the stale room over the freshly loaded post. So the queue is flushed
    BEFORE any save (an `apiFetch` middleware on the entity's REST
    route, the same seam de-rtc's `prepareForSave` uses), and when the
    tab goes hidden (a hidden tab cannot answer a joiner for up to
    120 s). An unsaved edit lost on reload is the editor's own
    unsaved-changes warning doing its job.

Cursors and selections stay on the base transport by decision: over the
channel they would point at content positions the receiver has not yet
polled for. Rethinking awareness for low-latency lanes is out of scope.

## What exists now

Server (`includes/class-gutenberg-sync-engines-advisory-presence.php`):

-   Per-tab presence tokens in a transient per room (never in sync
    storage: presence reads must not create a room's storage post).
    Stamped at editor page render, refreshed on every heartbeat, removed
    by a leave beacon on `pagehide`, expired after 300 s (a hidden tab's
    heartbeat slows to 120 s, so the TTL must span two beats).
-   The probe answer (`answer_probe`), shared by the heartbeat filter
    (`heartbeat_received`) and the poll route: records the token,
    stores outgoing handshake messages in per-recipient mailboxes (size
    and count capped, short expiry), and answers with the other tokens in
    the room, whether anyone else is present (tokens plus live sync
    awareness), and this tab's mailbox.
-   Page-render settings under `window._gutenbergSyncEnginesSettings
.advisory`: room, token, whether others are present, the STUN list
    (filterable), the peer cap, and the enabled flag.

Client:

-   `src/providers/advisory/signaling.ts`: the probe and mailbox, with
    two carriers: the heartbeat, and the sync poll itself (the request's
    `advisory` field, answered alongside the rooms) whenever the loop is
    active, which makes the handshake about two seconds at the company
    cadence. Discovered peers, "others present", send/receive handshake
    messages, the leave beacon.
-   `src/providers/advisory/channel.ts`: the WebRTC mesh. One peer
    connection and one data channel per discovered tab. The tab with the
    lower token initiates; ICE gathering completes before the offer or
    answer is sent, so a handshake is two heartbeat hops. Messages:
    `hello` (client id), `presence`, `announce`, `bye`. Coverage is
    computed from discovered tokens and the last awareness map.
-   `src/providers/http-polling/polling-manager.ts`: the cadence rules
    above, the held queues (released by company, a flush before a save
    via `save-flush.ts`, or the tab going hidden; codecs declaring
    `sendsWhileAlone` are exempt), the announce-after-send, the base
    presence overlay (per client, on top of the poll response's copy),
    and the long-poll disable hook.
-   Settings → Collaboration: a "Replacement transport" select (none,
    long polling, websocket) and an "Advisory channel" select (WebRTC or
    off, disabled while a replacement is chosen).
-   `src/providers/websocket/websocket-manager.ts`: the websocket
    transport as a replacement channel. While its socket is open it
    moves everything; whenever it is not (token refused, daemon
    unreachable, socket dropped) each room is PARKED with the polling
    manager at the cursor the socket had reached, and reclaimed at the
    cursor polling reached when the socket reopens, carrying whatever
    polling never sent (`pollingManager.releaseRoom`). One lane serves a
    room at a time, so nothing is replayed across the handoff.
-   `src/engines/de-rtc/session.ts`: announces after a commit lands
    through the autosave lane, since those rows never pass through the
    polling manager.

## Failure behavior

The rule: **the client behaves as if the channel did not exist, then
uses it to poll sooner and to show presence faster.**

-   Channel never connects (no STUN reachable, symmetric NAT without
    TURN, WebRTC disabled by a privacy extension): coverage stays false;
    today's cadence; nothing lost. TURN is deliberately not required.
-   A peer drops off the channel: coverage flips false on the
    `connectionstatechange`; the timer cadence resumes at once.
-   Nudge dropped: the safety poll catches up within 25 s.
-   Nudge storm: the coalescing delay and the floor bound polls per
    second; the server's existing size and room caps do the rest.
-   Heartbeat suspended (10 minutes idle) or the tab hidden (120 s
    cadence): the established channel survives; renegotiation waits for
    focus; the token expires after 300 s if the tab never beats again,
    and peers stop counting it.
-   More peers than the cap (8): the channel stands down and everyone
    polls. Full mesh is N(N-1)/2 connections; document editing rarely
    gets there.
-   The presence lane is missing (no `wp.heartbeat`, no per-post editor
    screen such as the site editor): the polling manager keeps its
    always-on cadence. Nothing about today's behavior changes there.
-   Queued work never waits for a slow timer. A coverage flip re-evaluates
    a pending timer; if that would replace a 1 s timer with the 25 s
    safety timer while updates are already queued (the intent-log undo
    spec caught exactly this: an undo's inverse intents sat unsent for
    25 s), the delay is cut to the on-demand send delay instead. The
    cadence rules decide how often to LOOK for rows; queued rows go out
    promptly regardless.

## What the client must never assume

-   That a nudge means new rows exist. Poll and find out.
-   That no nudge means nothing happened. Keep the safety timer.
-   That the channel knows who is in the room. The server does.
-   That the channel is authenticated the way the REST endpoint is.
-   That a peer on the channel is the only peer. Check the awareness map.

## Tests

-   Jest: `tests/js/providers/advisory/` (signaling payloads and
    mailbox; a two-tab mesh over a fake `RTCPeerConnection` wired through
    an in-memory signaling loop; coverage rules), and the polling manager
    cadence rules (quiet when alone, wake on company, on-demand polls
    under coverage, safety poll, announce coalescing, long-poll disable).
-   PHPUnit: `tests/phpunit/gutenbergSyncEnginesAdvisoryPresence.php`
    (token record and expiry, others-present from tokens and awareness,
    mailbox relay with caps, permission fence, leave route, page-render
    settings).
-   e2e: `tests/e2e/specs/http-only/collaboration-advisory-channel.spec.ts`
    (two tabs connect over the channel and an edit still propagates;
    idle polling drops to the safety cadence).
