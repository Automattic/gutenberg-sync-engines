# HTTP Polling Transport

The default sync transport: a periodic `POST /wp-sync/v1/updates` that batches
typed updates and awareness for every open room into a single request. The
transport is **engine-neutral** — it moves `{ type, data }` updates opaquely
and delegates their meaning to the active engine (intent-log, yjs-server, or a
third-party engine) on the server.

## Architecture

```
┌─────────────────┐                              ┌─────────────────┐
│    Client A     │                              │    Client B     │
│  ┌───────────┐  │                              │  ┌───────────┐  │
│  │  Engine   │  │                              │  │  Engine   │  │
│  │  session  │  │                              │  │  session  │  │
│  └─────┬─────┘  │                              │  └─────┬─────┘  │
│  ┌─────┴─────┐  │                              │  ┌─────┴─────┐  │
│  │  Polling  │  │                              │  │  Polling  │  │
│  │  Manager  │  │                              │  │  Manager  │  │
│  └─────┬─────┘  │                              │  └─────┬─────┘  │
└────────┼────────┘                              └────────┼────────┘
         │                                                │
         │         POST /wp-sync/v1/updates               │
         └────────────────────┬───────────────────────────┘
                              │
                    ┌─────────┴─────────┐
                    │  WordPress REST   │
                    │   sync server     │──▶ active engine
                    │  (transport only) │    (per-room ingest/read)
                    └─────────┬─────────┘
                              │
                       post-meta storage
```

## Key components

- Server: `includes/transports/class-wp-http-polling-sync-server.php` —
  registers the route, validates limits and permissions, then hands each
  room envelope to the active engine via the shared room-request seam.
  Storage is the framework's post-meta storage (one row per update; the
  cursor is an opaque monotonically increasing integer).
- Client: `http-polling-provider.ts` (per-room provider lifecycle),
  `polling-manager.ts` (singleton polling loop, queues, awareness),
  `config.ts` (intervals, limits, retry schedules), `types.ts` (wire types),
  `utils.ts` (queues, API helpers).

The http-long-polling transport reuses this entire client (and subclasses the
server), re-pointing the route at `/wp-sync/v1/long-poll` and holding empty
responses open server-side.

## Polling cadence

The loop is driven by the cadence rules in `docs/plan/advisory-channel.md`
(constants in `config.ts`):

- **Alone** (the presence lane says nobody else is in this post's room):
  only the 25 000 ms safety poll once the first poll has bootstrapped the
  session, and the room queues are HELD: local updates wait in the browser
  until company arrives, a save (an `apiFetch` middleware flushes them
  first, `save-flush.ts`), or the tab going hidden. Codecs that declare
  `sendsWhileAlone` (de-rtc) are exempt and send 300 ms after the first
  queued update. Company restarts the timer cadence and releases the queues.
- **Company, some peer not on the advisory channel**: 1000 ms (the
  "Polling interval" setting on Settings → Collaboration, 1-25 s, replaces
  this).
- **Company, every known peer on the advisory channel**: a 25 000 ms safety
  poll, plus polls on demand — 300 ms after a queued local update, and
  150 ms after a peer announces new rows (never two announce-driven polls
  closer than 250 ms).
- **Background tab**: 25 000 ms (kept below the server's 30 s awareness
  timeout so backgrounded tabs are not marked disconnected).
- **No presence lane on the page** (a screen without a per-post room, or
  the channel disabled): 4000 ms alone, 1000 ms with collaborators, as
  before.

Developers can *lower* (never raise) the active-tab intervals with the
`sync.pollingManager.pollingInterval` and
`sync.pollingManager.pollingIntervalWithCollaborators` filters; values above
the setting (or the defaults) are ignored. Faster polling increases request
volume.

On poll failure the manager backs off (solo: 2/4/8/12 s; with collaborators:
1/2/4/8 s), then shows a disconnect dialog that auto-retries every 30 s
(15 s after a manual retry). Failed updates are restored to the front of the
queue and re-sent verbatim.

## Wire shape

One request carries every open room (`types.ts`):

```json
{
	"rooms": [
		{
			"room": "postType/post:123",
			"client_id": 12345,
			"after": 987654,
			"engine": "intent-log",
			"engine_protocol": 1,
			"awareness": { "...": "..." },
			"updates": [ { "type": "intent", "data": "…" } ]
		}
	]
}
```

```json
{
	"rooms": [
		{
			"room": "postType/post:123",
			"end_cursor": 987660,
			"awareness": { "12345": { "...": "..." } },
			"dispositions": [ { "...": "..." } ],
			"updates": [ { "type": "intent", "data": "…" } ]
		}
	]
}
```

- `after` / `end_cursor` — the storage cursor; opaque to clients, echoed back
  as `after` on the next request.
- `engine` / `engine_protocol` — the client's engine identity stamp. A stale
  tab speaking the wrong engine (or a room whose storage lineage does not
  match) fails with **409 `rest_sync_engine_mismatch`** before anything is
  stored, and the client drops the room into the classic post-lock posture.
- `dispositions` — per-update engine acks (engine-specific; intent-log uses
  them for applied/escalated/voided).
- `updates[].type` / `updates[].data` — engine-owned; the transport never
  interprets them.
- `debug: true` on a room request asks the engine for a `_debug` diagnostics
  envelope in the response — served only when the `wp_sync_debug_enabled`
  filter allows it (default: `SCRIPT_DEBUG`). The `window.wpSync` sync
  inspector (`src/debug/inspector.ts`) uses this and records per-poll
  durations.

## Limits and permissions

- Request body ≤ 16 MB server-side (the client packs to a 15 MB budget and
  shrinks its budget on request-too-large responses, down to a 2 MB floor).
- ≤ 50 rooms per request (extra rooms rotate through subsequent polls).
- ≤ 1 MB per encoded update string.
- Requests require a logged-in user with `edit_posts` plus per-entity edit
  permission for each room, and each `client_id` is bound to the user that
  first used it.

## Awareness

Presence/cursor state travels with every poll. The server stores it per room
and expires clients that have not polled within 30 seconds. When awareness
shows more than one client, the manager treats the room as having company.
Peers on the advisory channel also send BASE presence (who they are: user
info, name, activity) directly to each other; the manager overlays it per
client on the poll response's map before handing it to the session. Cursors
and selections stay on the polls by decision: over the channel they would
point at content the receiver has not polled for yet. The poll request also
carries the channel's signaling probe (`advisory`), answered alongside the
rooms, so an active loop is a faster handshake carrier than the heartbeat.

## Limitations

- **Latency floor is the poll interval** — worst-case propagation is ~1 s
  with collaborators (4 s solo). Fine for document editing; coarse for
  high-fidelity cursor tracking.
- **Every poll is a full WordPress REST request**, including idle polls; per
  active collaborator, expect roughly one request per second of load while a
  session is live.
- The historical `should_compact` field remains on the wire for
  compatibility but every engine answers `false` — compaction is
  engine-owned and server-side now (the retired yjs-relay engine
  nominated a client compactor). The old `compaction_request` field is
  gone from the client entirely.
