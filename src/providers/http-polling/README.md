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

From `config.ts`:

- **Solo editing**: 4000 ms; the update queue starts paused and updates are
  not sent until a collaborator appears.
- **With collaborators**: 1000 ms.
- **Background tab**: 25 000 ms (kept below the server's 30 s awareness
  timeout so backgrounded tabs are not marked disconnected).

Hosts can *lower* (never raise) the active-tab intervals with the
`sync.pollingManager.pollingInterval` and
`sync.pollingManager.pollingIntervalWithCollaborators` filters; values above
the defaults are ignored. Faster polling increases request volume.

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
shows more than one client, the manager speeds up polling and resumes the
update queue.

## Limitations

- **Latency floor is the poll interval** — worst-case propagation is ~1 s
  with collaborators (4 s solo). Fine for document editing; coarse for
  high-fidelity cursor tracking.
- **Every poll is a full WordPress REST request**, including idle polls; per
  active collaborator, expect roughly one request per second of load while a
  session is live.
- Historical compaction fields (`should_compact`, `compaction_request`)
  remain on the wire for compatibility but the policy is engine-owned now
  (yjs-server and intent-log both checkpoint server-side; the retired
  yjs-relay engine nominated a client compactor).
