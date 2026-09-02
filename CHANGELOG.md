# Changelog

Significant changes to this plugin, newest first: new features, new
settings or extension points, and behavior that is removed or works
differently. Bug fixes, tests, developer tooling, refactors, and docs are
not listed here as highlights. Add entries under **Unreleased** as part
of the change itself; when a version ships they move under its heading,
followed by the full list of pull requests merged since the previous
release, which the release script generates from the commit history.

## Unreleased

### Added

-   An advisory channel between the browser tabs editing one post: a
    direct WebRTC link, discovered and negotiated through the heartbeat
    WordPress already sends and through the sync polls themselves,
    carrying who is present and "new changes, go and poll" notices,
    never content. With every peer reachable over it, tabs poll on
    demand plus a 25-second safety poll instead of on a timer; a tab
    that cannot reach a peer keeps the timer cadence. Filters:
    `gutenberg_sync_engines_advisory_enabled`,
    `gutenberg_sync_engines_advisory_ice_servers`,
    `gutenberg_sync_engines_advisory_max_peers`; console:
    `wpSync.advisory()`. See `docs/plan/advisory-channel.md`.

-   Settings → Collaboration has a "Replacement transport" select
    (none, long polling, websocket) and an "Advisory channel" select
    (WebRTC or off, disabled while a replacement transport is chosen).

### Changed

-   A lone editor's updates are held in the browser until company
    arrives, a save (they are flushed through the room first, so a
    reload never bootstraps from a room that missed them), or the tab
    going hidden; meanwhile the tab polls only the 25-second safety
    timer. De-rtc is exempt (its codec declares `sendsWhileAlone`); the
    engines' `syncWhileSolo` capability is gone.

### Added

-   Activating the plugin now turns on Gutenberg's real-time collaboration
    experiment, so collaboration works right after activation instead of
    needing a second trip to the Gutenberg → Experiments screen. Other
    experiments are left as they are, and the experiment checkbox keeps
    working afterward. Network-wide activation turns it on for every site
    ([#82](https://github.com/Automattic/gutenberg-sync-engines/issues/82)).
-   Under the de-rtc engine, every block now carries a durable identity
    (`metadata.syncId`), the same scheme the intent-log engine uses.
    Blocks of a saved post get a deterministic id from the post id and
    the block's position, computed identically by the server and by
    each editor, so nobody has to agree on it over the wire; blocks
    added during a session get a random id in the editor; blocks
    written by scripts that know nothing about identity adopt the id
    of the block they replaced and get a fresh one when they are new.
    The ids live in the block delimiters, so they persist into the
    saved post, survive a reload, and let the editor keep a block's
    internal id across incoming updates instead of remounting it.
-   Under the de-rtc engine, edits inside nested blocks now merge
    block by block. Two people editing different paragraphs inside the
    same Group both land, a paragraph added inside a container lands
    next to the one it followed, a block moved elsewhere keeps the edit
    a peer made to it, and a deletion wins over a concurrent edit with
    that edit held for review instead of lost. Before, the server lined
    blocks up by their top-level position and treated a Group as one
    unit, so any two edits inside the same Group parked one of them.
    Only a true clash on the same block is held back now, and the
    review card attaches to that block wherever it sits. The same
    identity drives three more things: an author without permission
    to publish raw HTML has only the risky block itself held back
    (not the whole container it sits in), the "who last edited this"
    record credits the block that changed rather than its container,
    and undo reverts a nested edit in place, removes a block you added,
    or brings back one you deleted.
-   Release automation: a "Create release PR" workflow (pick patch, minor, or
    major) opens a version-bump PR; merging it into trunk triggers a release
    workflow that builds and publishes a ready-to-install plugin zip as a
    GitHub release.
-   The release zip is self-contained: it bundles the pinned, built Gutenberg
    plugin, and the plugin now loads that bundled copy automatically when no
    other Gutenberg is present.
-   Polling interval setting on Settings → Collaboration for the HTTP
    short-polling transport (1-25 seconds; 0 keeps the defaults). Sets how
    often each editor asks the server for updates while collaborating; solo
    editing keeps its slower default unless the chosen interval is longer.
-   The storage backend is now swappable end to end. The framework gains a
    `wp_get_sync_storage()` factory behind a `__unstable_wp_sync_storage` filter, and
    every plugin code path obtains storage through it (via
    `gutenberg_sync_engines_storage()`), so a drop-in plugin can substitute
    Redis or another backend in one place. The framework storage also gains
    a non-creating lookup (`peek_room_engine` — looking at a room no longer
    creates it) and a real `reset_room()`; three plugin code paths that each
    re-implemented the non-creating lookup by hand (including one raw SQL
    delete) now use them. The storage interface documents the contract a
    substitute must uphold.
-   The per-room lock and the compare-and-swap primitive each gained a
    drop-in backend seam: implement `WP_Sync_Lock_Backend` or
    `WP_Sync_CAS_Backend` and return it from the `wp_sync_lock_backend` /
    `wp_sync_cas_backend` filter (for example, memcached locks). The
    interfaces document the correctness rules; the options-table
    implementations remain the defaults.

### Changed

-   Both engines with a review lane now store an edit set aside for review
    under the same row type, `parked` (intent-log wrote `proposal`, de-rtc
    wrote `proposal-parked`). The `resolved` row is unchanged. The name
    shows in the browser wire inspector and the `wp collaboration rooms`
    diagnostics; rooms written before this change are not migrated.
-   Adopt and Reject decisions now travel only over their own REST route,
    for every content type. The server rejects the older way (folded in
    with ordinary sync messages) and the browser no longer falls back to
    it; a decision that fails to send reopens in the review panel so it
    can be retried
    ([#40](https://github.com/Automattic/gutenberg-sync-engines/issues/40)).
-   Real-time collaboration is now turned on by the **Real-time
    collaboration** Gutenberg experiment instead of the Settings → Writing
    checkbox, following the framework
    ([WordPress/gutenberg#80658](https://github.com/WordPress/gutenberg/pull/80658)).
    Collaboration is off until that experiment is enabled, and the old
    `wp_collaboration_enabled` option is deleted on upgrade. Settings →
    Collaboration now says so when collaboration is off.
-   Conflict review plumbing moved into the framework: an engine now hands
    `createSyncManager` a `review` source and the manager drives the review
    panel, cards, and notices from it. The plugin's review-manager decorator
    (a workaround for the manager dropping the review handlers) is deleted;
    the de-rtc adapter composes the plain manager.

### Removed

-   The de-rtc engine no longer reads the transition-era data written
    before the announce model: the protocol-1 `content` rows and the
    `de_rtc_doc` room meta. Rooms written before that model are not
    migrated (the plugin has no installed base); reset them instead. The
    polling transport's deprecated `COMPACTION_THRESHOLD` constant is
    gone too (compaction has been engine-owned since 7.2.0).
-   `restoreProposalWithChanges()` (modify-before-adopt): API-only, never
    called by anything. To return together with its review-panel UI when a
    "suggested edits" feature starts.

## Pre-release history

The plugin's first published GitHub release is 0.1.0, produced by the
release workflow. The milestones below predate it: they were internal
version numbers on paper, never packaged or tagged, and were retired when
release automation landed (the plugin sits at 0.0.0 until that first
release ships).

### V1 loop (August 2026)

-   DE-RTC commit cadence setting (seconds; 0 commits on every settle).
-   DE-RTC Stage 2: sessions commit through the ordinary autosave endpoint;
    the transport carries advisories (~200-byte announce rows), not documents.
-   Fixed a DE-RTC commit hold that dropped the tail of a typing burst when
    the burst straddled a commit round trip.
-   Fixed intent-log losing an edit made during the join round trip on an
    empty room.
-   Collaborative undo in all three engines; cross-engine conflict review
    (intent-log manager, DE-RTC parked proposals in the framework review
    panel); shared genesis property seeding.
-   The websocket e2e suite now runs against the real websocket transport.
-   `composer lint` clean; zero-warning baseline.

### Engines, transports, diagnostics (August 2026)

-   Third engine: `de-rtc` (Distributed Editing's save-centric model; merge
    core ported verbatim from wordpress-develop).
-   Second engine: `yjs-server` (server-authoritative CRDT on vendored
    y-php); the client-merging `yjs-relay` engine was retired.
-   Transports: `http-polling` (default), `http-long-polling`, `websocket`.
-   Diagnostics: `npm run doctor`, the `window.wpSync` wire inspector,
    `wp collaboration rooms`, session capture/replay, the browser fuzzer,
    and the benchmark harnesses.

### Initial split from Gutenberg (August 2026)

-   Initial split from the Gutenberg framework: this plugin owns all engines
    and transports; the framework keeps the engine-neutral substrate. First
    engine: `intent-log`. Settings → Collaboration screen for choosing the
    active engine and transport.
